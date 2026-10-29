<?php

namespace App\Http\Controllers\Admin;

use App\Auth\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Own profile (admin + manager): password and TOTP two-factor login (CONCEPT.md §9).
 */
class ProfileController extends Controller
{
    public function show(Request $request, TwoFactor $twoFactor): View
    {
        $user = $request->user();
        $pending = $user->totp_secret !== null && ! $user->hasTwoFactor();

        return view('admin.profile', [
            'user' => $user,
            'pending' => $pending,
            'qr' => $pending ? $twoFactor->qrSvg($user) : null,
            'secret' => $pending ? $user->totp_secret : null,
            'recoveryLeft' => count($user->recovery_codes ?? []),
            'required' => TwoFactor::required(),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(12)->max(255)],
        ]);
        $request->user()->update(['password' => $data['password']]);
        $this->endOtherSessions($request);
        Audit::log('user.password_changed', $request->user());

        return back()->with('status', __('portal.saved'));
    }

    public function beginTwoFactor(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        abort_if($request->user()->hasTwoFactor(), 409);
        $twoFactor->begin($request->user());

        return redirect()->route('admin.profile');
    }

    public function confirmTwoFactor(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:16']])['code'];
        $user = $request->user();
        abort_if($user->hasTwoFactor() || $user->totp_secret === null, 409);

        $codes = $twoFactor->confirm($user, $code);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => __('portal.auth.two_factor_failed')]);
        }
        $this->endOtherSessions($request);
        Audit::log('two_factor.enabled', $user);

        return redirect()->route('admin.profile')->with('recovery_codes', $codes)
            ->with('status', __('portal.profile.two_factor_enabled'));
    }

    public function regenerateRecoveryCodes(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->confirmed($request, $twoFactor);
        $codes = $twoFactor->regenerateRecoveryCodes($user);
        Audit::log('two_factor.recovery_codes', $user);

        return redirect()->route('admin.profile')->with('recovery_codes', $codes);
    }

    public function disableTwoFactor(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        if (TwoFactor::required()) {
            throw ValidationException::withMessages(['code' => __('portal.profile.two_factor_required')]);
        }
        $user = $this->confirmed($request, $twoFactor);
        $twoFactor->disable($user);
        $this->endOtherSessions($request);
        Audit::log('two_factor.disabled', $user);

        return redirect()->route('admin.profile')->with('status', __('portal.profile.two_factor_disabled'));
    }

    /** Keep only this session (with a new id); other sessions and remember tokens end. */
    private function endOtherSessions(Request $request): void
    {
        $request->user()->invalidateSessions($request->session()->getId());
        $request->session()->regenerate(true);
    }

    /** Password plus a current TOTP or recovery code. */
    private function confirmed(Request $request, TwoFactor $twoFactor): User
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 409);
        if (! $twoFactor->verify($user, $data['code'])) {
            Audit::log('auth.two_factor_failed', $user);

            throw ValidationException::withMessages(['code' => __('portal.auth.two_factor_failed')]);
        }

        return $user;
    }
}
