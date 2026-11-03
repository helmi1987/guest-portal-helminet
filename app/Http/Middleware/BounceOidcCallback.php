<?php

namespace App\Http\Middleware;

use App\Auth\Oidc\OidcSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OIDC callback vs. the SameSite=Strict admin session cookie (CONCEPT.md §9): the identity provider
 * redirects the browser cross-site, so the cookie holding the login flow (state, nonce, PKCE verifier)
 * is not sent. Without the cookie and without the marker `bounce=1` the callback answers a tiny page
 * that reloads the same URL plus `bounce=1` (meta refresh, no script, no external resources); that
 * navigation starts on this origin, so the browser sends the cookie. Runs before the session starts,
 * so the bounce answer sets no new session cookie that would replace the one holding the flow. With
 * the marker the callback runs as usual (and fails as before when the flow is still missing).
 */
class BounceOidcCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->cookies->has((string) config('session.cookie')) || $request->query('bounce') === '1'
            || ! OidcSettings::enabled()) {
            return $next($request);
        }

        $url = $request->getBaseUrl().$request->getPathInfo().'?'.http_build_query(['bounce' => '1'] + $request->query());
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">'
            .'<meta http-equiv="refresh" content="0;url='.e($url).'"><title>'.e(__('portal.oidc.title')).'</title></head>'
            // No closing html tag (optional in HTML): Livewire injects its assets only into pages that have one.
            .'<body><p><a href="'.e($url).'">'.e(__('portal.guest.continue')).'</a></p>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
