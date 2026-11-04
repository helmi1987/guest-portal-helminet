<?php

namespace App\Jobs;

use App\Models\SessionSummary;
use App\Nas\ControllerSessions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renews the controller authorization of a running session with the grant's new remaining time after an
 * admin extended or shortened it (controller sites only, decision 2026-11-04). A device that is not
 * connected cannot be addressed; its authorization keeps the old end until the next login.
 */
class ReauthorizeControllerSession implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $sessionSummaryId) {}

    public function handle(ControllerSessions $sessions): void
    {
        $summary = SessionSummary::query()->with(['site', 'grant'])->find($this->sessionSummaryId);
        if ($summary !== null) {
            $sessions->reauthorize($summary);
        }
    }
}
