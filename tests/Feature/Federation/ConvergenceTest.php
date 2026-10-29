<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\Applier;
use App\Models\Device;
use App\Models\Grant;
use App\Models\MacWhitelist;
use App\Models\Site;
use App\Models\SiteCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Federation part C: conflict rules converge, i.e. the final state does not depend on the order in
 * which the events of several origins arrive (property-style test with random interleavings).
 */
class ConvergenceTest extends TestCase
{
    use RefreshDatabase, SimulatesInstances;

    private const TABLES = ['site_codes', 'devices', 'mac_whitelist', 'sites', 'users'];

    private const ORDERS = 25;

    public function test_final_state_is_independent_of_the_event_order(): void
    {
        config(['portal.federation.max_wait_attempts' => 100000]);
        $events = $this->conflictingEvents();

        $reference = null;
        mt_srand(7);
        for ($i = 0; $i < self::ORDERS; $i++) {
            $this->makeInstances('t');
            $this->applyInterleaved($events, $i === 0 ? 'origin-by-origin' : ($i === 1 ? 'reverse' : 'random'));
            $state = $this->state('t');
            $this->assertConstraintsHold($state);
            if ($reference === null) {
                $reference = $state;
            } else {
                $this->assertEquals($reference, $state, "Order #$i diverged");
            }
        }

        // The source instances converge to the same state as well.
        $this->syncAll('a', 'b', 'c');
        foreach (['a', 'b', 'c'] as $name) {
            $this->assertEquals($reference, $this->state($name), "Instance $name diverged");
        }
    }

    /**
     * Partitioned instances a, b, c write conflicting rows (incl. later changes of the winners).
     *
     * @return array<string, list<object>> origin => events in seq order
     */
    private function conflictingEvents(): array
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        $this->makeInstances('a', 'b', 'c');
        $site = $this->on('a', fn () => Site::create(['slug' => 'lobby', 'name' => 'Lobby', 'driver' => 'mikrotik_ros7']));
        $grant = $this->on('a', fn () => Grant::create(['type' => GrantType::Code, 'code' => '123456', 'duration_s' => 3600,
            'expiry_mode' => ExpiryMode::FirstLogin]));
        $this->syncAll('a', 'b', 'c'); // shared starting point, then partition
        $mac = 'AA:BB:CC:DD:EE:FF';
        $tick = function (int $minutes) {
            Carbon::setTestNow(Carbon::parse('2026-10-30 10:00:00')->addMinutes($minutes));
        };

        // Site codes: loser L (a, oldest), winner W (b), then W deactivated (b); X on c in between.
        $tick(1);
        $this->on('a', fn () => SiteCode::create(['site_id' => $site->id, 'code' => '111111', 'duration_s' => 60]));
        $tick(2);
        $w = $this->on('b', fn () => SiteCode::create(['site_id' => $site->id, 'code' => '222222', 'duration_s' => 60]));
        $tick(3);
        $this->on('c', fn () => SiteCode::create(['site_id' => $site->id, 'code' => '333333', 'duration_s' => 60]));
        $tick(4);
        $this->on('b', fn () => SiteCode::find($w->id)->update(['active' => false, 'deactivated_at' => now()]));

        // Devices of one grant on three instances; the newest one is unbound later by its admin.
        $tick(5);
        $this->on('c', fn () => Device::create(['grant_id' => $grant->id, 'mac' => '00:00:00:00:00:03', 'bound_at' => now()]));
        $tick(6);
        $this->on('a', fn () => Device::create(['grant_id' => $grant->id, 'mac' => '00:00:00:00:00:01', 'bound_at' => now()]));
        $tick(7);
        $d2 = $this->on('b', fn () => Device::create(['grant_id' => $grant->id, 'mac' => '00:00:00:00:00:02', 'bound_at' => now()]));
        $tick(8);
        $this->on('b', fn () => Device::find($d2->id)->update(['unbound_at' => now()]));

        // MAC whitelist: three entries for one MAC, the newest deleted again.
        $tick(9);
        $this->on('a', fn () => MacWhitelist::create(['mac' => $mac, 'comment' => 'a']));
        $tick(10);
        $this->on('c', fn () => MacWhitelist::create(['mac' => $mac, 'comment' => 'c']));
        $tick(11);
        $m = $this->on('b', fn () => MacWhitelist::create(['mac' => $mac, 'comment' => 'b']));
        $tick(12);
        $this->on('b', fn () => MacWhitelist::find($m->id)->delete());

        // Slugs and emails: three rows each with the same value; one moves away later.
        $tick(13);
        foreach (['a', 'b', 'c'] as $name) {
            $this->on($name, fn () => Site::create(['slug' => 'hall', 'name' => "Hall $name", 'driver' => 'mikrotik_ros7']));
            $user = $this->on($name, fn () => User::factory()->create(['email' => 'admin@example.com']));
            $this->on($name, fn () => User::find($user->id)->forceFill(['oidc_subject' => 'iss sub'])->save());
            usleep(1500); // distinct ULID times
        }
        $tick(14);
        $this->on('b', fn () => Site::query()->where('name', 'Hall b')->first()->update(['slug' => 'room']));
        $this->on('a', fn () => User::query()->where('email', 'admin@example.com')->first()->update(['email' => 'boss@example.com']));

        $events = [];
        foreach (['a', 'b', 'c'] as $name) {
            $id = $this->idOf($name);
            $events[$id] = $this->on($name, fn () => DB::table('federation_events')->where('origin', $id)->orderBy('seq')->get()->all());
        }

        return $events;
    }

    /** Apply one event at a time, choosing the origin per step; waiting events are retried later. */
    private function applyInterleaved(array $events, string $mode): void
    {
        $queues = array_map(fn ($list) => array_values($list), $events);
        if ($mode === 'reverse') {
            $queues = array_reverse($queues, true);
        }
        $guard = 0;
        while (array_filter($queues) !== []) {
            $origins = array_keys(array_filter($queues));
            $origin = $mode === 'random' ? $origins[mt_rand(0, count($origins) - 1)] : $origins[0];
            $event = $queues[$origin][0];
            $result = $this->on('t', fn () => app(Applier::class)->apply($origin, [$event]));
            if ($result->lastSeq >= $event->seq) {
                array_shift($queues[$origin]);
            } else {
                $this->assertSame('stopped', $result->status, (string) $result->reason);
                if ($mode !== 'random') {
                    // Deterministic modes: move on to the next origin.
                    $queues = array_slice($queues, 1, null, true) + array_slice($queues, 0, 1, true);
                }
            }
            $this->assertLessThan(100000, ++$guard);
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function state(string $name): array
    {
        return $this->on($name, function () {
            $state = [];
            foreach (self::TABLES as $table) {
                $state[$table] = DB::table($table)->orderBy('id')->get()
                    ->map(fn ($r) => array_diff_key((array) $r, ['remember_token' => true]))->all();
            }

            return $state;
        });
    }

    private function assertConstraintsHold(array $state): void
    {
        $activeCodes = array_filter($state['site_codes'], fn ($r) => $r['active'] && $r['deactivated_at'] === null && $r['deleted_at'] === null);
        $this->assertCount(1, $activeCodes);
        $activeDevices = array_filter($state['devices'], fn ($r) => $r['unbound_at'] === null && $r['deleted_at'] === null);
        $this->assertCount(1, $activeDevices);
        $liveMacs = array_filter($state['mac_whitelist'], fn ($r) => $r['deleted_at'] === null);
        $this->assertCount(1, $liveMacs);
        foreach (['sites' => 'slug', 'users' => 'email'] as $table => $column) {
            $values = array_column($state[$table], $column);
            $this->assertSame(count($values), count(array_unique($values)));
        }
    }
}
