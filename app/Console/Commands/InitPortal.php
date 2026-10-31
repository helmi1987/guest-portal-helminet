<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Federation\Resync;
use App\Federation\ResyncRequiredException;
use App\Federation\Topology;
use App\Models\User;
use App\Support\LocalInstance;
use Illuminate\Console\Command;

class InitPortal extends Command
{
    protected $signature = 'portal:init';

    protected $description = 'Ensure the local instance identity (id, federation pin, signing key) exists (run at container start)';

    public function handle(LocalInstance $instance, Topology $topology): int
    {
        $this->line('Instance: '.$instance->id());
        $this->detectRestore($topology);
        try {
            $model = $instance->refreshIdentity();
            $this->line('Federation certificate: '.($model->cert_fingerprint ?? 'none'));
        } catch (ResyncRequiredException) {
            // The identity changed (e.g. new federation certificate) while the resync blocks writes.
            $this->warn('Instance identity not updated: federation resync after restore in progress.');
        }
        if (Resync::required()) {
            $this->warn('Federation resync after restore: replicated data is read-only until the own events are back from the peers.');
        }

        if (! User::query()->where('role', Role::Admin)->exists()) {
            $this->warn('No admin user yet. Create one with: artisan portal:admin');
        }

        return self::SUCCESS;
    }

    /**
     * A backup extracted into the data folder leaves its manifest there (CONCEPT.md §9 restore). A
     * federated instance then fetches its own events back before it writes again (Resync); the manifest
     * is renamed, so the next start does not trigger again.
     */
    private function detectRestore(Topology $topology): void
    {
        $marker = (string) config('portal.backup.restore_marker');
        if ($marker === '' || ! is_file($marker)) {
            return;
        }
        if ($topology->isFederated()) {
            Resync::mark('backup restored (manifest in the data folder)');
        }
        $done = preg_replace('/\.json$/', '', $marker).'.restored-'.now()->format('Ymd-His').'.json';
        if (! @rename($marker, $done)) {
            $this->warn("Cannot rename $marker: remove it, otherwise every start is treated as a restore.");
        }
        $this->line('Backup restore detected'.($topology->isFederated() ? ': federation resync required.' : '.'));
    }
}
