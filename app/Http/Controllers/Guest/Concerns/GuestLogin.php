<?php

namespace App\Http\Controllers\Guest\Concerns;

use App\Auth\Social\LoginMethods;
use App\Models\Grant;
use App\Models\RadiusToken;
use App\Models\Site;
use App\Nas\HotspotContext;
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

    /** Error page when a login method is switched off or not usable now (decision 2026-11-03). */
    protected function methodUnavailable(string $method): ?Response
    {
        return (LoginMethods::available()[$method] ?? false) ? null : $this->error('method_disabled', 403);
    }

    protected function handoff(Request $request, Grant $grant, Site $site, HotspotContext $context, RadiusToken $token, string $password): View
    {
        $request->session()->put('handoff_token', $token->id);
        // Valid until the grant ends (plus a margin for the "expired" page).
        $statusUrl = url(URL::temporarySignedRoute('guest.status', $grant->refresh()->expires_at->copy()->addHour(),
            ['grant' => $grant->id, 'token' => $token->id], absolute: false));

        return view('guest.handoff', $site->driver()->loginHandoff($context, $token->username, $password, $statusUrl));
    }
}
