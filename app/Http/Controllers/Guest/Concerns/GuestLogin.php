<?php

namespace App\Http\Controllers\Guest\Concerns;

use App\Auth\Social\LoginMethods;
use App\Models\Grant;
use App\Models\RadiusToken;
use App\Models\Site;
use App\Nas\ControllerSessions;
use App\Nas\HotspotContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Shared steps of the guest login controllers (CONCEPT.md §7.1): hotspot context of the session, error
 * pages, method switches and the handoff of one-time credentials to the router.
 */
trait GuestLogin
{
    /** @return array{0: ?Site, 1: HotspotContext} */
    protected function current(Request $request): array
    {
        $portal = $request->session()->get('portal');
        $site = $portal ? Site::find($portal['site']) : null;

        return [$site, HotspotContext::fromArray($site ? $portal['context'] : [])];
    }

    protected function error(string $kind, int $status = 200): Response
    {
        return response()->view('guest.error', ['kind' => $kind], $status);
    }

    /** Error page when a login method is switched off or not usable now (for the site, if given; decision 2026-11-03). */
    protected function methodUnavailable(string $method, ?Site $site = null): ?Response
    {
        return (LoginMethods::available($site)[$method] ?? false) ? null : $this->error('method_disabled', 403);
    }

    /** Signed status page of one login, valid until the grant ends (plus a margin for the "expired" page). */
    protected function statusUrl(Grant $grant, RadiusToken $token): string
    {
        return url($this->signedGuestUrl('guest.status', $grant, $token));
    }

    /** Relative signed URL of a route of one login (status page, logout), valid like the status page. */
    protected function signedGuestUrl(string $route, Grant $grant, RadiusToken $token): string
    {
        return URL::temporarySignedRoute($route, ($grant->expires_at ?? now())->copy()->addHour(),
            ['grant' => $grant->id, 'token' => $token->id], absolute: false);
    }

    protected function handoff(Request $request, Grant $grant, Site $site, HotspotContext $context, RadiusToken $token, string $password): View|Response|RedirectResponse
    {
        $request->session()->put('handoff_token', $token->id);
        $statusUrl = $this->statusUrl($grant->refresh(), $token);

        // Controller-based NAS (decision 2026-11-04, v0.27.0): the portal lets the device out through the
        // controller API, records the session itself and sends the browser to the status page.
        if (ControllerSessions::supports($site)) {
            $result = app(ControllerSessions::class)->authorize($site, $grant, $token, $context);
            if (! $result->granted) {
                return $this->error('controller_failed', 502);
            }
            // The original URL is offered only as a link on the status page (never an automatic redirect).
            $request->session()->put('controller_orig_url', $context->origUrl);

            return redirect()->to($statusUrl);
        }

        return view('guest.handoff', $site->driver()->loginHandoff($context, $token->username, $password, $statusUrl));
    }
}
