<?php

namespace App\Jobs;

use App\Federation\Sync;
use App\Federation\Topology;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push new events to the directly connected peers (CONCEPT.md §10.2). Dispatched after commit of
 * local writes and after relayed events were applied on the hub; one queued job is enough (unique
 * until processing). Failures are left to the next push or the pull every 30 s.
 */
class PushEvents implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public static function dispatchIfFederated(): void
    {
        if (app(Topology::class)->directPeers()->isNotEmpty()) {
            self::dispatch();
        }
    }

    public function handle(Sync $sync): void
    {
        $sync->pushAll();
    }
}
