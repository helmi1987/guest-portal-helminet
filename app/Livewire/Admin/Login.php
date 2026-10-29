<?php

namespace App\Livewire\Admin;

use App\Auth\TwoFactor;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin login: password, then the TOTP or recovery code when 2FA is enabled (CONCEPT.md §9, §12).
 * Between the steps only the user id waits in the session (5 minutes); nothing is logged in yet.
 * No "remember me": every new session needs the full login (a remember cookie would skip 2FA).
 */
#[Layout('components.layouts.admin')]
class Login extends Component
{
    private const PENDING = 'login.two_factor';

    private const PENDING_TTL = 300;

    /** Code failures per pending login; then the password is needed again. */
    private const CODE_TRIES = 5;

    /** Code failures per IP within 5 minutes (across users). */
    private const CODE_TRIES_PER_IP = 10;

    /** Max. login_failed audit rows per hour (instance-wide); beyond that only one log warning. */
    private const FAILED_AUDIT_PER_HOUR = 100;

    public string $email = '';

    public string $password = '';

    public string $code = '';

    public bool $twoFactor = false;

    public function mount(): void
    {
        $this->twoFactor = $this->pendingUser() !== null;
    }

    public function login(): void
    {
        $this->validate(['email' => 'required|string|email', 'password' => 'required|string']);

        $key = 'admin-login|'.Str::lower($this->email).'|'.request()->ip();
        $this->throttle($key, 'email');

        $user = User::query()->where('email', $this->email)->first();
        if ($user === null) {
            // Same cost as a real check: no user enumeration by timing.
            Hash::check($this->password, Cache::rememberForever('admin-login.dummy-hash', fn () => Hash::make(Str::random(32))));
        }
        if ($user === null || ! Auth::getProvider()->validateCredentials($user, ['password' => $this->password])) {
            RateLimiter::hit($key, 60);
            $this->auditFailedLogin($user);

            throw ValidationException::withMessages(['email' => __('portal.auth.failed')]);
        }
        RateLimiter::clear($key);
        $this->password = '';

        if ($user->hasTwoFactor()) {
            session()->regenerate();
            session()->put(self::PENDING, ['user' => $user->id, 'at' => now()->timestamp]);
            $this->twoFactor = true;

            return;
        }

        $this->complete($user, 'password');
    }

    public function verify(TwoFactor $twoFactor): void
    {
        $this->validate(['code' => 'required|string|max:32']);
        $user = $this->pendingUser();
        if ($user === null) {
            $this->cancel();

            throw ValidationException::withMessages(['email' => __('portal.auth.two_factor_expired')]);
        }

        $ipKey = 'admin-2fa-ip|'.request()->ip();
        $userKey = 'admin-2fa|'.$user->id;
        $this->throttle($ipKey, 'code', self::CODE_TRIES_PER_IP);
        if (! $twoFactor->verify($user, $this->code)) {
            RateLimiter::hit($ipKey, 300);
            RateLimiter::hit($userKey, self::PENDING_TTL);
            Audit::log('auth.two_factor_failed', $user, [], $user);
            $this->code = '';

            if (RateLimiter::attempts($userKey) >= self::CODE_TRIES) {
                // Start over with the password instead of locking the account.
                RateLimiter::clear($userKey);
                $this->cancel();

                throw ValidationException::withMessages(['email' => __('portal.auth.two_factor_restart')]);
            }

            throw ValidationException::withMessages(['code' => __('portal.auth.two_factor_failed')]);
        }
        RateLimiter::clear($userKey);
        session()->forget(self::PENDING);

        $this->complete($user, 'two_factor');
    }

    public function cancel(): void
    {
        session()->forget(self::PENDING);
        $this->reset('code', 'twoFactor', 'password');
    }

    public function render()
    {
        return view('livewire.admin.login')->title(__('portal.auth.title'));
    }

    private function complete(User $user, string $method): void
    {
        Auth::login($user);
        session()->regenerate();
        Audit::log('auth.login', $user, ['method' => $method], $user);

        $this->redirectIntended(route('admin.dashboard'));
    }

    /** Audit rows for failed logins are capped, so guessing cannot flood the table. */
    private function auditFailedLogin(?User $user): void
    {
        $key = 'audit-login-failed';
        if (RateLimiter::tooManyAttempts($key, self::FAILED_AUDIT_PER_HOUR)) {
            if (Cache::add('audit-login-failed-capped', true, 3600)) {
                Log::warning('Failed admin logins exceed the audit cap; further failures are not audited this hour.');
            }

            return;
        }
        RateLimiter::hit($key, 3600);
        $email = filter_var($this->email, FILTER_VALIDATE_EMAIL) ? mb_substr($this->email, 0, 255) : '[invalid]';
        Audit::log('auth.login_failed', $user, ['email' => $email], $user, $email);
    }

    private function pendingUser(): ?User
    {
        $pending = session()->get(self::PENDING);
        if (! is_array($pending) || now()->timestamp - (int) ($pending['at'] ?? 0) > self::PENDING_TTL) {
            return null;
        }
        $user = User::query()->find($pending['user'] ?? null);

        return $user?->hasTwoFactor() ? $user : null;
    }

    private function throttle(string $key, string $field, int $max = 5): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([
                $field => __('portal.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }
    }
}
