<?php

namespace Tests\Feature\Federation;

use App\Federation\Outbox;
use App\Models\MacWhitelist;
use App\Models\Site;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Part J: tombstones are purged only beyond the retention horizon and after every direct peer
 * acknowledged them; the change log is compacted the same way; purging writes no events.
 */
class RetentionTest extends TestCase
{
    use BuildsFederation, FederatedHttp, RefreshDatabase, SimulatesInstances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFederation();
    }

    private function purge(string $name): void
    {
        $this->on($name, fn () => Artisan::call('portal:purge'));
    }

    private function exists(string $name, string $id): bool
    {
        return $this->on($name, fn () => MacWhitelist::withTrashed()->whereKey($id)->exists());
    }

    public function test_tombstone_waits_for_every_peer_and_the_horizon(): void
    {
        $id = $this->on('a', function () {
            $site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']);
            $entry = MacWhitelist::create(['mac' => 'AA:BB:CC:00:00:01', 'comment' => 'Printer', 'active' => true]);
            $entry->syncSites([$site->id]);

            return $entry->id;
        });
        $this->syncRound();
        $this->assertTrue($this->exists('b', $id));

        // Deleted while b is down: the hub has the tombstone, b not.
        $this->down = ['b'];
        $this->on('a', fn () => MacWhitelist::findOrFail($id)->delete());
        $this->syncRound(['a']);
        $this->assertNotNull($this->rowOn('hub', 'mac_whitelist', $id)['deleted_at']);
        $this->assertNull($this->rowOn('b', 'mac_whitelist', $id)['deleted_at']);

        // Within the retention period nothing is purged.
        $this->purge('a');
        $this->purge('hub');
        $this->assertTrue($this->exists('a', $id));

        $this->travel(400)->days();
        $this->syncRound(['a']);
        $ownBefore = $this->on('a', fn () => DB::table(Outbox::TABLE)->where('origin', app(LocalInstance::class)->id())->max('seq'));
        $this->purge('a');
        $this->purge('hub');
        // a's only direct peer (the hub) has it: purged there; the hub waits for b.
        $this->assertFalse($this->exists('a', $id));
        $this->assertTrue($this->exists('hub', $id));
        // The local purge records no events.
        $this->assertSame($ownBefore, $this->on('a', fn () => DB::table(Outbox::TABLE)->where('origin', app(LocalInstance::class)->id())->max('seq')));

        // b comes back, gets the tombstone from the hub (beyond the horizon, but the row is known there).
        $this->down = [];
        $this->syncRound();
        $this->assertNotNull($this->rowOn('b', 'mac_whitelist', $id)['deleted_at']);
        $this->syncRound();
        $this->purge('hub');
        $this->purge('b');
        foreach (['hub', 'b'] as $name) {
            $this->assertFalse($this->exists($name, $id), "purged on $name");
            $this->assertSame(0, $this->on($name, fn () => DB::table('mac_whitelist_site')->where('mac_whitelist_id', $id)->count()));
        }
    }

    public function test_change_log_keeps_current_versions_and_the_last_sequence(): void
    {
        $siteId = $this->on('a', function () {
            $site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']);
            $site->update(['name' => 'Site A2']);
            $site->update(['name' => 'Site A3']);

            return $site->id;
        });
        $this->syncRound();
        $idA = $this->idOf('a');
        $maxA = $this->on('hub', fn () => (int) DB::table(Outbox::TABLE)->where('origin', $idA)->max('seq'));

        $this->purge('hub');
        $this->assertSame(3, $this->on('hub', fn () => DB::table(Outbox::TABLE)->where('record_id', $siteId)->count()),
            'nothing compacted within the retention period');

        $this->travel(400)->days();
        $this->syncRound();
        foreach (['hub', 'a', 'b'] as $name) {
            $this->purge($name);
            // Superseded versions are gone, the current version of the site stays.
            $this->assertSame(['Site A3'], $this->on($name, fn () => DB::table(Outbox::TABLE)->where('record_id', $siteId)
                ->pluck('payload')->map(fn ($p) => json_decode($p, true)['name'])->all()), "log on $name");
            // The highest sequence of every origin stays (numbering continues).
            $this->assertSame($maxA, $this->on($name, fn () => (int) DB::table(Outbox::TABLE)->where('origin', $idA)->max('seq')));
        }
        $this->on('a', fn () => Site::findOrFail($siteId)->update(['name' => 'Site A4']));
        $this->assertSame($maxA + 1, $this->on('a', fn () => (int) DB::table(Outbox::TABLE)->where('origin', $idA)->max('seq')));
        $this->syncRound();
        $this->assertSame('Site A4', $this->rowOn('b', 'sites', $siteId)['name']);
    }
}
