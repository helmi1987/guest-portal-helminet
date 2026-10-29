<?php

namespace App\Http\Controllers;

use App\Models\RadiusAccounting;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal Captive Portal API (RFC 8908), announced to guests via DHCP option 114 (RFC 8910).
 * "captive" is false when the client IP has a live RADIUS session at this site. This needs the real
 * guest IP: with source NAT of guests towards the portal (docs/guest-network-setup.md 7.4) every
 * guest would appear captive, so do not announce option 114 in that setup.
 */
class CaptivePortalApiController extends Controller
{
    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $headers = ['Content-Type' => 'application/captive+json', 'Cache-Control' => 'private, no-store'];
        $site = Site::query()->where('slug', $slug)->first();
        if ($site === null) {
            // Unknown site: generic answer, no URL (no site enumeration, no 404 page for the client).
            return response()->json(['captive' => true], 200, $headers);
        }

        $fresh = now()->subSeconds(3 * (int) config('portal.radius.interim_interval'));
        $online = RadiusAccounting::query()
            ->where('last_update_at', '>=', $fresh)
            ->whereHas('summary', fn ($q) => $q->where('site_id', $site->id)
                ->where('ip', $request->ip())->whereNull('stopped_at'))
            ->exists();

        // Guests must start at the router's hotspot login (it adds MAC and login URL for the portal).
        $host = ((array) $site->setting('hotspot_hosts', []))[0] ?? null;
        $portalUrl = $host ? 'https://'.$host.'/login' : rtrim((string) ($site->setting('portal_url') ?: config('app.url')), '/').'/';

        return response()->json(['captive' => ! $online, 'user-portal-url' => $portalUrl], 200, $headers);
    }
}
