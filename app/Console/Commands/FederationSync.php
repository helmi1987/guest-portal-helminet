<?php

namespace App\Console\Commands;

use App\Federation\Sync;
use Illuminate\Console\Command;

/**
 * Heartbeat, pull and push with the directly connected peers (scheduler, every 30 s, CONCEPT.md §10.2).
 */
class FederationSync extends Command
{
    protected $signature = 'portal:federation:sync';

    protected $description = 'Replicate with the other federation instances (heartbeat, pull, push)';

    public function handle(Sync $sync): int
    {
        foreach ($sync->syncAll() as $peer => $outcome) {
            $this->line("$peer: $outcome");
        }

        return self::SUCCESS;
    }
}
