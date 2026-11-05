<?php

namespace App\Services;

use App\Enums\GrantStatus;
use App\Models\Device;
use App\Models\Grant;
use App\Models\SessionSummary;
use App\Models\Site;

/**
 * Why a device returning to the login page lost its access at this site (CONCEPT.md §7.1):
 * 'logged_out' (last session at this site ended by the guest: portal logout or router-side logout, decision
 * 2026-11-05), 'disconnected' (last session at this site ended by an admin), 'revoked' or 'expired' (latest
 * grant bound to the MAC), only within portal.ended_notice_hours. Null for unknown MACs, devices
 * with a usable grant for this site, or older events. Returns only a reason, no personal data.
 */
final class AccessEndedNotice
{
    public const LOGGED_OUT = 'logged_out';

    public const DISCONNECTED = 'disconnected';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    public static function for(?string $mac, Site $site): ?string
    {
        $hours = (int) config('portal.ended_notice_hours');
        if ($mac === null || $mac === '' || $hours <= 0) {
            return null;
        }
        $since = now()->subHours($hours);

        // Last session of this MAC at this site: ended by the guest or disconnected by an admin (grant may still be valid).
        $session = SessionSummary::query()->where('site_id', $site->id)->where('mac', $mac)
            ->whereNotNull('grant_id')->latest('started_at')->first();
        if ($session !== null && ($session->logged_out_at ?? $session->stopped_at ?? $session->started_at)->gte($since) && $session->loggedOut()) {
            return self::LOGGED_OUT;
        }
        if ($session?->kicked_at !== null && $session->kicked_at->gte($since)) {
            return self::DISCONNECTED;
        }

        // Latest grant bound to this MAC for this site; none if another grant still gives access.
        $grants = Device::query()->where('mac', $mac)->latest('bound_at')->limit(20)->pluck('grant_id')->unique()
            ->map(fn ($id) => Grant::query()->with('siteLinks')->find($id))
            ->filter(fn (?Grant $g) => $g !== null && $g->allowsSite($site->id))->values();
        if ($grants->contains(fn (Grant $g) => $g->isUsable() && $g->activeDevice()?->mac === $mac)) {
            return null;
        }
        $latest = $grants->first();

        return match (true) {
            $latest === null => null,
            $latest->status() === GrantStatus::Revoked && $latest->revoked_at->gte($since) => self::REVOKED,
            $latest->status() === GrantStatus::Expired && $latest->expires_at->gte($since) => self::EXPIRED,
            default => null,
        };
    }
}
