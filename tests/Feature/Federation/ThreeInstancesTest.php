<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\EventCodec;
use App\Federation\Federation;
use App\Federation\Outbox;
use App\Federation\SigningKey;
use App\Federation\Topology;
use App\Models\FederationPeer;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\Setting;
use App\Models\Site;
use App\Services\GrantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Part L: three instances (hub, a, b) over the simulated federation API end to end: partition and
 * rejoin with a double redemption, relay through the hub, clock skew, schema and protocol mismatch.
 * All replicated tables end up identical on every instance. (Hub takeover: TakeoverTest.)
 */
class ThreeInstancesTest extends TestCase
{
    use BuildsFederation, FederatedHttp, RefreshDatabase, SimulatesInstances;

    private const T0 = '2026-10-31 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::T0);
        $this->buildFederation();
    }

    private function at(int $minutes): void
    {
        Carbon::setTestNow(Carbon::parse(self::T0)->addMinutes($minutes));
    }

    /** Replicated state of an instance (local columns left out). */
    private function state(string $name): array
    {
        return $this->on($name, function () {
            $state = [];
            foreach (array_keys(Federation::TABLES) as $table) {
                $state[$table] = DB::table($table)->orderBy('id')->get()
                    ->map(fn ($row) => array_diff_key((array) $row, array_flip(Federation::localColumns($table))))->all();
            }

            return $state;
        });
    }

    private function assertConverged(): void
    {
        $hub = $this->state('hub');
        $this->assertEquals($hub, $this->state('a'), 'a diverged from the hub');
        $this->assertEquals($hub, $this->state('b'), 'b diverged from the hub');
    }

    private function grantOn(string $name, string $code, array $extra = []): Grant
    {
        return $this->on($name, fn () => Grant::create(['type' => GrantType::Code, 'code' => $code, 'duration_s' => 3600,
            'expiry_mode' => ExpiryMode::FirstLogin] + $extra));
    }

    /** Portal login + RADIUS binding of a device at an instance (what a redemption writes). */
    private function redeem(string $name, string $grantId, string $siteId, string $mac): string
    {
        return $this->on($name, function () use ($grantId, $siteId, $mac) {
            $grant = Grant::findOrFail($grantId);
            $guest = Guest::create(['first_name' => 'Guest', 'last_name' => $mac, 'locale' => 'en']);
            app(GrantManager::class)->issueToken($grant, $guest, Site::findOrFail($siteId), $mac);

            return app(GrantManager::class)->bindDevice($grant->fresh(), $mac, $guest->id)->id;
        });
    }

    public function test_partition_rejoin_double_redemption_and_relay(): void
    {
        // Normal codes stay usable while the hub is unreachable (offline rule off).
        $this->on('hub', fn () => Setting::put(Topology::OFFLINE_SMS_ONLY_SETTING, false));
        $site = $this->on('hub', fn () => Site::create(['slug' => 'lobby', 'name' => 'Lobby', 'driver' => 'mikrotik_ros7']));
        $grant = $this->grantOn('hub', '555555');
        $this->syncRound();
        $this->assertConverged();

        // Partition: the hub is unreachable, a and b cannot sync with each other either.
        $this->down = ['hub'];
        $this->at(1);
        $deviceA = $this->redeem('a', $grant->id, $site->id, 'AA:AA:AA:00:00:01');
        $this->at(2);
        $deviceB = $this->redeem('b', $grant->id, $site->id, 'BB:BB:BB:00:00:02');
        $onlyB = $this->grantOn('b', '666666', ['note' => 'written during the partition']);
        $this->on('a', fn () => Site::findOrFail($site->id)->update(['name' => 'Lobby (a)']));
        $this->syncRound();
        $this->assertNull($this->rowOn('a', 'grants', $onlyB->id));
        $this->assertSame('Lobby', $this->rowOn('b', 'sites', $site->id)['name']);

        // Rejoin: everything converges, one active device per code (the newer binding).
        $this->down = [];
        $this->at(3);
        $this->syncRound();
        $this->syncRound();
        $this->assertConverged();
        foreach (['hub', 'a', 'b'] as $name) {
            $this->assertNotNull($this->rowOn($name, 'grants', $onlyB->id), "relayed to $name");
            $this->assertSame('Lobby (a)', $this->rowOn($name, 'sites', $site->id)['name']);
            $this->assertNull($this->rowOn($name, 'devices', $deviceB)['unbound_at'], "newer device active on $name");
            $this->assertNotNull($this->rowOn($name, 'devices', $deviceA)['unbound_at'], "older device unbound on $name");
            // First login stays the earliest one (a), with its expiry.
            $this->assertSame(Carbon::parse(self::T0)->addMinute()->toDateTimeString(), $this->rowOn($name, 'grants', $grant->id)['first_login_at']);
        }

        // A revocation at b reaches a through the hub.
        $this->on('b', fn () => app(GrantManager::class)->revoke(Grant::findOrFail($grant->id)));
        $this->syncRound();
        $this->assertNotNull($this->rowOn('a', 'grants', $grant->id)['revoked_at']);
        $this->assertConverged();
    }

    public function test_clock_skew_pauses_one_instance_the_others_continue(): void
    {
        $idB = $this->idOf('b');
        $idHub = $this->idOf('hub');
        // b's clock is 6 minutes ahead: the hub pauses b (and b the hub); hub and a go on.
        $this->clockOffset['b'] = 360;
        $this->on('hub', fn () => Artisan::call('portal:federation:sync'));
        $this->assertTrue($this->on('hub', fn () => FederationPeer::findOrFail($idB)->isPaused()));
        $fromA = $this->grantOn('a', '777777');
        $fromB = $this->grantOn('b', '888888');
        $this->syncRound();
        $this->assertNotNull($this->rowOn('hub', 'grants', $fromA->id));
        $this->assertNull($this->rowOn('hub', 'grants', $fromB->id));
        $this->assertNull($this->rowOn('b', 'grants', $fromA->id));

        // Clock fixed, resumed (federation page or repair command): catch-up in both directions.
        $this->clockOffset['b'] = 0;
        $this->on('hub', fn () => Artisan::call('portal:federation:repair', ['action' => 'resume', 'instance' => $idB]));
        $this->on('b', fn () => Artisan::call('portal:federation:repair', ['action' => 'resume', 'instance' => $idHub]));
        $this->syncRound();
        $this->assertNotNull($this->rowOn('a', 'grants', $fromB->id));
        $this->assertNotNull($this->rowOn('b', 'grants', $fromA->id));
        $this->assertConverged();
    }

    public function test_newer_schema_and_newer_protocol_of_one_instance(): void
    {
        // b runs a newer schema: an extra column travels in its events and is ignored elsewhere.
        $this->on('b', fn () => DB::statement("ALTER TABLE grants ADD COLUMN future_flag TEXT DEFAULT 'x'"));
        $newer = $this->grantOn('b', '121212');
        $this->syncRound();
        $this->assertNotNull($this->rowOn('a', 'grants', $newer->id));
        $this->assertArrayNotHasKey('future_flag', $this->rowOn('a', 'grants', $newer->id));

        // b's next event carries a higher protocol (rolling update): its origin pauses at the hub,
        // the hub's own changes still reach a and b.
        $idB = $this->idOf('b');
        $this->down = ['hub']; // not pushed before the event is changed
        $pending = $this->on('b', fn () => DB::transaction(function () {
            $pending = Grant::create(['type' => GrantType::Code, 'code' => '131313', 'duration_s' => 3600,
                'expiry_mode' => ExpiryMode::FirstLogin]);
            $event = DB::table(Outbox::TABLE)->where('record_id', $pending->id)->sole();
            $proto = Federation::FEDERATION_PROTOCOL + 1;
            DB::table(Outbox::TABLE)->where('id', $event->id)->update(['proto' => $proto, 'sig' => app(SigningKey::class)->sign(
                EventCodec::message($event->origin, (int) $event->seq, $event->table, $event->record_id, $event->version, $proto, $event->payload))]);

            return $pending;
        }));
        $fromHub = $this->grantOn('hub', '141414');
        $this->down = [];
        $this->syncRound();
        $this->assertNull($this->rowOn('hub', 'grants', $pending->id));
        $this->assertStringContainsString('protocol', (string) $this->on('hub',
            fn () => DB::table('peer_cursors')->where('origin', $idB)->value('pause_reason')));
        $this->assertNotNull($this->rowOn('a', 'grants', $fromHub->id));
        $this->assertNotNull($this->rowOn('b', 'grants', $fromHub->id));
        $this->assertNull($this->rowOn('a', 'grants', $pending->id));
    }
}
