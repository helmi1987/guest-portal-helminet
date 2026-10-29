<?php

namespace App\Console\Commands;

use App\Jobs\DisconnectSession;
use App\Models\RadiusAccounting;
use App\Models\SessionSummary;
use App\Support\LocalInstance;
use Illuminate\Console\Command;

/**
 * Disconnects open sessions whose grant ended (expired, shortened, revoked) or whose device was
 * rebound, and sessions of MAC whitelist entries no longer valid (expired, inactive, deleted, site
 * removed); closes sessions the router stopped reporting (e.g. lost Accounting-Stop).
 */
class EnforceGrants extends Command
{
    protected $signature = 'portal:enforce-grants';

    protected $description = 'Disconnect sessions of ended grants and close stale sessions';

    public function handle(LocalInstance $instance): int
    {
        $open = SessionSummary::query()->whereNull('stopped_at')
            ->where('serving_instance_id', $instance->id())
            ->with(['grant' => fn ($q) => $q->withTrashed(), 'macWhitelist.siteLinks', 'accounting'])
            ->get();

        $staleAfter = 3 * (int) config('portal.radius.interim_interval') + 60;
        $disconnected = 0;

        foreach ($open as $session) {
            $acct = $session->accounting;
            $lastSeen = $acct->max('last_update_at') ?? $session->started_at;
            if ($lastSeen->lt(now()->subSeconds($staleAfter))) {
                $session->update(['stopped_at' => $lastSeen]);

                continue;
            }

            $ended = $session->mac_whitelist_id !== null
                ? ! ($session->macWhitelist?->isValidFor($session->mac, $session->site_id) ?? false)
                : $this->grantEnded($session);
            $recentlySent = $acct->contains(fn (RadiusAccounting $a) => $a->disconnect_sent_at?->gt(now()->subMinutes(5)));

            if ($ended && ! $recentlySent) {
                DisconnectSession::dispatch($session->id);
                $disconnected++;
            }
        }

        $this->line("Disconnect requested for $disconnected session(s).");

        return self::SUCCESS;
    }

    private function grantEnded(SessionSummary $session): bool
    {
        $grant = $session->grant;

        return $grant === null || $grant->trashed() || ! $grant->isUsable()
            || $grant->activeDevice()?->mac !== $session->mac;
    }
}
