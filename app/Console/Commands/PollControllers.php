<?php

namespace App\Console\Commands;

use App\Models\SessionSummary;
use App\Nas\ControllerSessions;
use App\Support\LocalInstance;
use Illuminate\Console\Command;

/**
 * Session summaries of controller sites (UniFi, experimental, decision 2026-11-04): without RADIUS
 * accounting the controller is asked for each open session's device (connected, IP, usage counters of
 * the current authorization). Devices the controller reports as not connected keep their session (the
 * authorization outlives a short disconnect); portal:enforce-grants ends sessions whose access ended.
 */
class PollControllers extends Command
{
    protected $signature = 'portal:poll-controllers';

    protected $description = 'Update sessions of controller sites (UniFi) from the controller';

    public function handle(LocalInstance $instance, ControllerSessions $sessions): int
    {
        $open = SessionSummary::query()->whereNull('stopped_at')->whereNotNull('grant_id')
            ->where('serving_instance_id', $instance->id())->with('site')->get()
            ->filter(fn (SessionSummary $s) => ControllerSessions::supports($s->site));

        $seen = 0;
        foreach ($open as $session) {
            $status = $session->site->driver()->clientStatus($session->site, $session->mac);
            if ($status !== null && $status['connected']) {
                $sessions->record($session, $status);
                $seen++;
            }
        }
        if ($open->isNotEmpty()) {
            $this->line("Controller sessions: {$open->count()} open, $seen connected.");
        }

        return self::SUCCESS;
    }
}
