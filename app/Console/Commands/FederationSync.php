<?php

namespace App\Console\Commands;

use App\Federation\BrandingFiles;
use App\Federation\Sync;
use Illuminate\Console\Command;

/**
 * Heartbeat, pull and push with the directly connected peers (scheduler, every 30 s, CONCEPT.md §10.2),
 * then missing branding files (§10.3).
 */
class FederationSync extends Command
{
    protected $signature = 'portal:federation:sync';

    protected $description = 'Replicate with the other federation instances (heartbeat, pull, push)';

    public function handle(Sync $sync, BrandingFiles $branding): int
    {
        foreach ($sync->syncAll() as $peer => $outcome) {
            $this->line("$peer: $outcome");
        }
        // Branding files still missing (failed fetches are retried after a while).
        foreach ($branding->fetchMissing() as $file => $fetched) {
            $this->line("branding $file: ".($fetched ? 'fetched' : 'not available yet'));
        }

        return self::SUCCESS;
    }
}
