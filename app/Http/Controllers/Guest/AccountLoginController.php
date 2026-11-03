<?php

namespace App\Http\Controllers\Guest;

use App\Auth\Directory\DirectoryException;
use App\Auth\Directory\DirectoryLogin;
use App\Auth\Directory\DirectorySettings;
use App\Auth\Directory\DirectoryUser;
use App\Auth\Social\LoginMethods;
use App\Enums\GrantType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Guest\Concerns\GuestLogin;
use App\Models\Guest;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Nas\HotspotContext;
use App\Services\GrantManager;
use App\Services\LoginThrottle;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Guest login with the user name and password of the site's Active Directory / LDAP (CONCEPT.md §7.2 E,
 * decision 2026-11-03, v0.24.0). The password is checked by a bind against the site's directory, sent
 * nowhere else, never stored, logged, audited or flashed back. Lockout like the other methods (per MAC,
 * IP and global, login attempt `account`) plus a limit per site and user name, counted before the bind,
 * so guessing cannot lock the account in the directory faster than that.
 */
class AccountLoginController extends Controller
{
    use GuestLogin;

    public function login(Request $request, DirectoryLogin $directory, GrantManager $grants, LoginThrottle $throttle): View|Response|RedirectResponse
    {
        [$site, $context] = $this->current($request);
        if ($site === null || ! $context->isComplete()) {
            return redirect()->route('guest.portal');
        }
        if ($refused = $this->methodUnavailable(LoginMethods::ACCOUNT, $site)) {
            return $refused;
        }
        $data = $request->validate([
            'username' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:256'],
            'terms' => ['accepted'],
            'terms_version' => ['nullable', 'string', 'max:26'],
        ]);
        // The guest must have accepted the version that is current now (it may change meanwhile).
        $terms = TermsVersion::current(app()->getLocale());
        if (($data['terms_version'] ?? null) !== $terms?->id) {
            throw ValidationException::withMessages(['terms' => __('portal.guest.errors.terms_changed')]);
        }
        $username = DirectoryLogin::normaliseUsername($data['username']);
        if ($username === null) {
            throw ValidationException::withMessages(['username' => __('portal.guest.errors.account_invalid')]);
        }

        $mac = $context->mac;
        $ip = $request->ip();
        $userKey = self::userKey($site->id, $username);
        if ($throttle->isLocked($mac, $ip) || RateLimiter::tooManyAttempts($userKey, self::perAccount())) {
            return $this->error('locked', 429);
        }
        // Counted before the bind (atomic increment): parallel guesses cannot exceed the limit per account.
        RateLimiter::hit($userKey, (int) config('portal.login_limits.window_minutes') * 60);

        $log = ['site' => $site->slug, 'mac' => $mac];
        try {
            // Outside the login lock: the directory may take up to its timeout to answer.
            $user = $directory->authenticate(DirectorySettings::config($site), $username, $data['password']);
        } catch (DirectoryException $e) {
            if ($e->unavailable()) {
                // Nothing to learn for the guest: not counted as a failed attempt.
                Log::warning('Directory account login: directory not usable', $log + ['reason' => $e->reason, 'error' => $e->getMessage()]);

                return $this->error('account_unavailable', 503);
            }
            $throttle->record($mac, $ip, $site->id, LoginMethods::ACCOUNT, false);
            Log::notice('Directory account login refused', $log + ['reason' => $e->reason]);
            $message = match ($e->reason) {
                DirectoryException::ACCOUNT_RESTRICTED => 'portal.guest.errors.account_restricted',
                DirectoryException::NOT_MEMBER => 'portal.guest.errors.account_not_allowed',
                default => 'portal.guest.errors.account_invalid',
            };
            throw ValidationException::withMessages(['username' => __($message)]);
        }
        RateLimiter::clear($userKey);

        return $this->complete($request, $user, $terms, $site, $context, $grants, $throttle);
    }

    /** Guest, terms acceptance, grant of type account and handoff, like the social login. */
    private function complete(Request $request, DirectoryUser $user, ?TermsVersion $terms, Site $site, HotspotContext $context, GrantManager $grants,
        LoginThrottle $throttle): View|Response
    {
        $mac = $context->mac;
        $result = $throttle->attempt($mac, $request->ip(), $site->id, LoginMethods::ACCOUNT, fn () => DB::transaction(function () use ($user, $site, $mac, $terms, $grants) {
            $guest = Guest::create([
                'first_name' => $user->firstName,
                'last_name' => $user->lastName,
                'locale' => app()->getLocale(),
                'email' => $user->email,
            ]);
            // Each login gets its own grant (like SMS), time running from now, restricted to the site.
            $grant = $grants->createTimedGrant(GrantType::Account, $site, DirectorySettings::siteDurationS($site), $site->name);
            TermsAcceptance::create([
                'guest_id' => $guest->id,
                'terms_version_id' => $terms?->id,
                'grant_id' => $grant->id,
                'site_id' => $site->id,
                'mac' => $mac,
                'accepted_at' => now(),
            ]);
            [$token, $password] = $grants->issueToken($grant, $guest, $site, $mac);

            return [$grant, $token, $password];
        }));
        if ($result === false) {
            return $this->error('locked', 429);
        }
        [$grant, $token, $password] = $result;
        // Site and user name only: no DN, no attributes, never the password.
        Audit::log('guest.account_login', $grant, ['site' => $site->slug, 'username' => $user->username]);
        Log::info('Guest account login', ['grant' => $grant->id, 'site' => $site->slug, 'mac' => $mac, 'terms_version' => $terms?->number]);

        return $this->handoff($request, $grant, $site, $context, $token, $password);
    }

    /** Failed attempts allowed per site and user name within the login window. */
    private static function perAccount(): int
    {
        return max(1, (int) config('portal.login_limits.per_account', 5));
    }

    /** Rate limiter key: site and user name (case-insensitive, hashed). */
    private static function userKey(string $siteId, string $username): string
    {
        return 'guest-account:'.hash('sha256', $siteId."\0".mb_strtolower($username));
    }
}
