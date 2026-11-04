<?php

namespace App\Jobs;

use App\Models\SessionSummary;
use App\Nas\ControllerSessions;
use App\Nas\ReportsDisconnectSupport;
use App\Radius\Disconnector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Ends all open RADIUS sessions of a session summary on the serving router; on a controller site
 * (AuthorizesViaController) the controller ends the authorization instead and the summary is closed.
 */
class DisconnectSession implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public string $sessionSummaryId) {}

    public function handle(Disconnector $disconnector): void
    {
        $summary = SessionSummary::find($this->sessionSummaryId);
        if ($summary === null || $summary->stopped_at !== null) {
            return;
        }

        // Controller site (decision 2026-11-04): end the authorization through the controller API.
        if (ControllerSessions::supports($summary->site)) {
            $summary->accounting()->update(['disconnect_sent_at' => now()]);
            if (! $summary->site->driver()->deauthorizeClient($summary->site, $summary->mac)) {
                throw new RuntimeException('Controller did not end the authorization of session '.$summary->id);
            }
            $summary->accounting()->whereNull('nas_client_id')->update(['terminate_cause' => 'Controller-Unauthorized']);
            $summary->update(['stopped_at' => now()]);

            return;
        }

        // NAS without Disconnect-Request (decision 2026-11-04, v0.28.0): nothing to send, no retries; the grant stays
        // revoked and the Session-Timeout of the login ends access.
        $driver = $summary->site?->driver();
        if ($driver instanceof ReportsDisconnectSupport && ! $driver->supportsDisconnect($summary->site)) {
            Log::info('No Disconnect-Request for this device, access ends at Session-Timeout',
                ['session_summary_id' => $summary->id, 'site' => $summary->site->slug]);

            return;
        }

        $failed = false;
        foreach ($summary->accounting()->with('nasClient')->get() as $acct) {
            $acct->forceFill(['disconnect_sent_at' => now()])->save();
            $failed = ! $disconnector->disconnect($acct) || $failed;
        }

        if ($failed) {
            // Retried by the queue; Session-Timeout remains the upper bound.
            throw new RuntimeException('Disconnect-Request not acknowledged for session '.$summary->id);
        }
    }
}
