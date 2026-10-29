<?php

namespace App\Http\Middleware;

use App\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setting "require 2FA" (CONCEPT.md §9): users without confirmed TOTP can only reach their profile
 * (to enrol) and log out.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $user->hasTwoFactor() && TwoFactor::required()) {
            return redirect()->route('admin.profile')->with('status', __('portal.profile.two_factor_required'));
        }

        return $next($request);
    }
}
