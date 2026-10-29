<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\DataKey;
use App\Federation\EventCodec;
use App\Federation\FederatedSecrets;
use App\Federation\Federation;
use App\Federation\Inbox;
use App\Federation\Outbox;
use App\Federation\Pairing;
use App\Federation\Topology;
use App\Models\FederationPeer;
use App\Models\Grant;
use App\Models\Instance;
use App\Models\Site;
use App\Models\User;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Federation part F: signed events, push and pull in the star topology (relay by the hub), acks,
 * heartbeat with clock skew and protocol checks, hub reachability, data key distribution.
 */
class SyncTest extends TestCase
{
    use FederatedHttp, RefreshDatabase, SimulatesInstances;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 10:00:00');
    }

    /** Hub plus joined instances, all routed. */
    private function federation(string ...$spokes): void
    {
        $this->makeInstances('hub', ...$spokes);
        $pin = $this->giveCertificate('hub');
        foreach ($spokes as $spoke) {
            $this->giveCertificate($spoke);
        }
        $this->routeFederation(['hub', ...$spokes]);
        foreach ($spokes as $spoke) {
            $token = $this->on('hub', fn () => app(Pairing::class)->createToken(null)['token']);
            $code = $this->on($spoke, fn () => Artisan::call('portal:federation:join', ['url' => 'https://hub.example.com:9443',
                'token' => $token, 'fingerprint' => $pin]));
            $this->assertSame(0, $code, Artisan::output());
        }
    }

    private function sync(string $name): void
    {
        $this->on($name, fn () => Artisan::call('portal:federation:sync'));
    }

    private function grantOn(string $name, string $code = '123456'): Grant
    {
        return $this->on($name, fn () => Grant::create(['type' => GrantType::Code, 'code' => $code, 'duration_s' => 3600,
            'expiry_mode' => ExpiryMode::FirstLogin]));
    }

    public function test_local_events_are_signed_and_unsigned_or_forged_events_pause_the_origin(): void
    {
        $this->makeInstances('a', 'b');
        $this->grantOn('a');
        $idA = $this->idOf('a');
        $events = $this->on('a', fn () => DB::table(Outbox::TABLE)->orderBy('seq')->get()->map(fn ($e) => EventCodec::toWire($e))->all());
        $key = $this->on('a', fn () => Instance::find($idA)->signing_public_key);
        foreach ($events as $event) {
            $this->assertTrue(EventCodec::verify($event, $key));
        }

        // Forged payload (e.g. altered by a relaying instance): valid prefix applied, the event dropped,
        // the sending peer paused; the origin itself is not paused (no denial of service by a peer).
        $forged = $events;
        $last = count($forged) - 1;
        $forged[$last]['payload'] = str_replace('123456', '654321', $forged[$last]['payload']);
        $relay = '01JRELAY000000000000000000';
        $result = $this->on('b', fn () => app(Inbox::class)->receive($forged, null, $relay)[$idA]);
        $this->assertSame('stopped', $result->status);
        $this->assertStringContainsString('invalid signature', $result->reason);
        $this->assertSame($events[$last - 1]['seq'], $result->lastSeq);
        $this->assertNull($this->rowOn('b', 'grants', $events[$last]['record_id']));
        $this->assertNull($this->on('b', fn () => DB::table('peer_cursors')->where('origin', $idA)->value('paused_at')));
        $this->assertStringContainsString('invalid signature', $this->on('b', fn () => FederationPeer::find($relay)->pause_reason));
        // The genuine event from another route is applied.
        $this->assertSame('ok', $this->on('b', fn () => app(Inbox::class)->receive($events)[$idA])->status);
        $this->assertNotNull($this->rowOn('b', 'grants', $events[$last]['record_id']));

        // Unsigned events are rejected as well.
        $this->makeInstances('c');
        $unsigned = $events;
        $unsigned[0]['sig'] = null;
        $result = $this->on('c', fn () => app(Inbox::class)->receive($unsigned)[$idA]);
        $this->assertSame('stopped', $result->status);
        $this->assertStringContainsString('unsigned event at seq 1', $result->reason);

        // Unknown origin without self-introduction: waits.
        $this->makeInstances('d');
        $result = $this->on('d', fn () => app(Inbox::class)->receive(array_slice($events, 1))[$idA]);
        $this->assertSame('stopped', $result->status);
        $this->assertSame(0, $result->applied);
    }

    public function test_star_topology_pull_push_and_relay_through_the_hub(): void
    {
        $this->federation('a', 'b');
        $idA = $this->idOf('a');

        // Spoke a only talks to the hub, the hub to both.
        $this->assertSame([$this->idOf('hub')], $this->on('a', fn () => app(Topology::class)->directPeers()->pluck('id')->all()));
        $this->assertEqualsCanonicalizing([$idA, $this->idOf('b')], $this->on('hub', fn () => app(Topology::class)->directPeers()->pluck('id')->all()));

        // Pull: a's site reaches the hub (hub pulls a's events), then b (b pulls every origin from the hub).
        $site = $this->on('a', fn () => Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']));
        $this->sync('hub');
        $this->assertNotNull($this->rowOn('hub', 'sites', $site->id));
        $this->sync('b');
        $this->assertNotNull($this->rowOn('b', 'sites', $site->id));
        $this->assertTrue($this->on('b', fn () => DB::table(Outbox::TABLE)->where('origin', $idA)->whereNotNull('sig')->exists()));
        $this->sync('a');

        // Acks: every instance knows what its peers applied.
        $seqA = $this->on('a', fn () => (int) DB::table(Outbox::TABLE)->where('origin', $idA)->max('seq'));
        $this->assertSame($seqA, $this->on('a', fn () => FederationPeer::find($this->idOf('hub'))->ack($idA)));
        $this->assertSame($seqA, $this->on('hub', fn () => FederationPeer::find($this->idOf('b'))->ack($idA)));

        // Push after commit: a -> hub -> b (relay) without a sync run.
        $grant = $this->grantOn('a', '222222');
        $this->assertNotNull($this->rowOn('hub', 'grants', $grant->id));
        $this->assertNotNull($this->rowOn('b', 'grants', $grant->id));
        // And back: a change on b reaches a through the hub.
        $this->on('b', fn () => Grant::find($grant->id)->update(['label' => 'from b']));
        $this->assertSame('from b', $this->rowOn('a', 'grants', $grant->id)['label']);

        // Hub down: push fails quietly, the next sync catches up.
        $this->down = ['hub'];
        $this->on('a', fn () => Grant::find($grant->id)->update(['note' => 'offline edit']));
        $this->sync('a');
        $this->assertNotNull($this->on('a', fn () => FederationPeer::find($this->idOf('hub'))->last_error));
        $this->down = [];
        $this->sync('a');
        $this->sync('b');
        $this->assertSame('offline edit', $this->rowOn('b', 'grants', $grant->id)['note']);
    }

    public function test_clock_skew_warns_and_pauses_the_peer_until_resumed(): void
    {
        $this->federation('a');
        $hubId = $this->idOf('hub');

        $this->clockOffset['hub'] = 45;
        $this->sync('a');
        $state = $this->on('a', fn () => FederationPeer::find($hubId));
        $this->assertEqualsWithDelta(45000, $state->clock_skew_ms, 1000);
        $this->assertFalse($state->isPaused());

        $this->clockOffset['hub'] = 400;
        $site = $this->on('hub', fn () => Site::create(['slug' => 'later', 'name' => 'Later', 'driver' => 'mikrotik_ros7']));
        $this->sync('a');
        $state = $this->on('a', fn () => FederationPeer::find($hubId));
        $this->assertTrue($state->isPaused());
        $this->assertStringContainsString('clock skew 400 s', $state->pause_reason);
        $this->assertNull($this->rowOn('a', 'sites', $site->id));

        // The admin page shows the pause; resuming after the clock is fixed replicates again.
        $this->on('a', function () use ($hubId) {
            $this->actingAs(User::factory()->admin()->create());
            $this->onZone('admin')->get('/admin/federation')->assertOk()->assertSee('clock skew 400 s');
            $this->clockOffset['hub'] = 0;
            $this->onZone('admin')->post("/admin/federation/peers/$hubId/unpause")->assertRedirect();
        });
        $this->sync('a');
        $this->assertNotNull($this->rowOn('a', 'sites', $site->id));
    }

    public function test_higher_protocol_pauses_the_peer(): void
    {
        $this->federation('a');
        $idA = $this->idOf('a');
        $pem = $this->certificateOf('a');

        $this->on('hub', function () use ($idA, $pem) {
            $server = ['PORTAL_ZONE' => 'federation', 'PORTAL_CLIENT_CERT' => rawurlencode($pem)];
            $this->withServerVariables($server)->postJson('/federation/events', ['protocol' => Federation::FEDERATION_PROTOCOL + 1, 'events' => []])
                ->assertStatus(409);
            $this->assertTrue(FederationPeer::find($idA)->isPaused());
            // A paused peer gets nothing until resumed.
            $this->withServerVariables($server)->getJson('/federation/status')->assertStatus(409);
            $this->withServerVariables($server)->getJson('/federation/events?origin='.$idA.'&after=0')->assertStatus(409);
            $this->serverVariables = [];
        });
        // a sees the refusal as an error.
        $this->sync('a');
        $this->assertStringContainsString('409', (string) $this->on('a', fn () => FederationPeer::find($this->idOf('hub'))->last_error));
    }

    public function test_events_endpoint_pages_by_origin_and_sequence(): void
    {
        $this->federation('a');
        foreach (['111111', '222222', '333333'] as $code) {
            $this->grantOn('hub', $code);
        }
        $hubId = $this->idOf('hub');
        $pem = $this->certificateOf('a');
        $this->on('hub', function () use ($hubId, $pem) {
            $events = $this->withServerVariables(['PORTAL_ZONE' => 'federation', 'PORTAL_CLIENT_CERT' => rawurlencode($pem)])
                ->getJson("/federation/events?origin=$hubId&after=2&limit=2")->assertOk()->json('events');
            $this->serverVariables = [];
            $this->assertSame([3, 4], array_column($events, 'seq'));
            $this->assertIsString($events[0]['payload']);
            $this->assertNotNull($events[0]['sig']);
        });
    }

    public function test_hub_reachability_and_limited_mode(): void
    {
        $this->federation('a');
        $this->sync('a');
        $this->assertFalse($this->on('a', fn () => app(Topology::class)->hubUnreachable()));

        $this->down = ['hub'];
        Carbon::setTestNow(now()->addMinutes(3));
        $this->sync('a');
        $this->assertTrue($this->on('a', fn () => app(Topology::class)->hubUnreachable()));
        $this->assertTrue($this->on('a', fn () => app(Topology::class)->limitedMode()));
        // Never on the hub itself.
        $this->assertFalse($this->on('hub', fn () => app(Topology::class)->limitedMode()));

        $this->down = [];
        $this->sync('a');
        $this->assertFalse($this->on('a', fn () => app(Topology::class)->limitedMode()));
    }

    public function test_rotated_data_key_reaches_the_other_instances(): void
    {
        $this->federation('a');
        $kid = $this->on('hub', fn () => app(FederatedSecrets::class)->rotate());
        $this->assertNotContains($kid, $this->on('a', fn () => app(DataKey::class)->knownIds()));

        $this->sync('a');
        $this->assertContains($kid, $this->on('a', fn () => app(DataKey::class)->knownIds()));
        $this->assertSame($kid, $this->on('a', fn () => app(DataKey::class)->currentId()));
        $this->assertNotNull($this->on('a', fn () => app(LocalInstance::class)->id()));
    }
}
