<?php

namespace App\Console\Commands;

use App\Jobs\DisconnectSession;
use App\Models\RadiusAccounting;
use App\Models\SessionSummary;
use App\Nas\ControllerSessions;
use App\Support\LocalInstance;
use Illuminate\Console\Command;

/**
 * Disconnects open sessions whose grant ended (expired, shortened, revoked) or whose device was
 * rebound, and sessions of MAC whitelist entries no longer valid (expired, inactive, deleted, site
 * removed); closes sessions the router stopped reporting (e.g. lost Accounting-Stop). Sessions of
 * controller sites (UniFi) are ended through the controller (DisconnectSession) instead.
 */
class EnforceGrants extends Command
{
    protected $signature = 'portal:enforce-grants';

    protected $description = 'Disconnect sessions of ended grants and close stale sessions';

    public function handle(LocalInstance $instance): int
    {
        $open = SessionSummary::query()->whereNull('stopped_at')
            ->where('serving_instance_id', $instance->id())
            ->with(['grant' => fn ($q) => $q->withTrashed(), 'macWhitelist.siteLinks', 'accounting', 'site'])
            ->get();

        $staleAfter = 3 * (int) config('portal.radius.interim_interval') + 60;
        $disconnected = 0;

        foreach ($open as $session) {
            $acct = $session->accounting;
            $recentlySent = $acct->contains(fn (RadiusAccounting $a) => $a->disconnect_sent_at?->gt(now()->subMinutes(5)));

            // Controller site (decision 2026-11-04): the session stands for the authorization on the
            // controller, not for the association; it stays open while the grant runs (no stale check)
            // and is ended through the controller once access ended.
            if (ControllerSessions::supports($session->site)) {
                if ($session->accessEnded() && ! $recentlySent) {
                    DisconnectSession::dispatch($session->id);
                    $disconnected++;
                }

                continue;
            }

            $lastSeen = $acct->max('last_update_at') ?? $session->started_at;
            if ($lastSeen->lt(now()->subSeconds($staleAfter))) {
                $session->update(['stopped_at' => $lastSeen]);

                continue;
            }

            $ended = $session->accessEnded();

            if ($ended && ! $recentlySent) {
                DisconnectSession::dispatch($session->id);
                $disconnected++;
            }
        }

        $this->line("Disconnect requested for $disconnected session(s).");

        return self::SUCCESS;
    }
}
