<?php

namespace App\Jobs;

use App\Models\SessionSummary;
use App\Radius\Disconnector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Ends all open RADIUS sessions of a session summary on the serving router.
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
