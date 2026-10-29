<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\DataKey;
use App\Federation\Outbox;
use App\Federation\Pairing;
use App\Federation\Topology;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\FederationPeer;
use App\Models\Grant;
use App\Models\Instance;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Federation part E: join token, fresh-instance rule, join with snapshot and data key, admin page.
 */
class PairingTest extends TestCase
{
    use FederationPeers, RefreshDatabase, SimulatesInstances;

    private string $hubPin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 10:00:00');
        $this->makeInstances('hub', 'new', 'third');
        $this->hubPin = $this->giveCertificate('hub');
        $this->giveCertificate('new');
        $this->giveCertificate('third');
        foreach (['hub', 'new', 'third'] as $name) {
            $this->on($name, fn () => app(LocalInstance::class)->refreshIdentity()
                ->update(['name' => ucfirst($name), 'federation_url' => "https://$name.example.com:9443"]));
        }
        // Calls to the hub's federation port are handled by the simulated hub, with the caller's certificate.
        Http::fake(['hub.example.com:9443/*' => function (Request $request) {
            $caller = $this->callerOf($request);

            return $this->on('hub', function () use ($request, $caller) {
                $response = $this->withServerVariables(['PORTAL_ZONE' => 'federation', 'PORTAL_CLIENT_CERT' => rawurlencode($this->certificateOf($caller))])
                    ->call($request->method(), parse_url($request->url(), PHP_URL_PATH), $request->method() === 'GET' ? $request->data() : [], [], [],
                        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                        $request->method() === 'GET' ? null : $request->body());
                $this->serverVariables = [];

                return Http::response($response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
            });
        }]);
    }

    private function callerOf(Request $request): string
    {
        $id = $request->header('X-Federation-Instance')[0];
        foreach (['hub', 'new', 'third'] as $name) {
            if ($this->idOf($name) === $id) {
                return $name;
            }
        }
        $this->fail('unknown caller');
    }

    private function token(): string
    {
        return $this->on('hub', fn () => app(Pairing::class)->createToken(null)['token']);
    }

    private function join(string $name, string $token, ?string $pin = null): int
    {
        return $this->on($name, fn () => Artisan::call('portal:federation:join', ['url' => 'https://hub.example.com:9443',
            'token' => $token, 'fingerprint' => $pin ?? $this->hubPin]));
    }

    public function test_fresh_instance_joins_with_snapshot_data_key_and_cursors(): void
    {
        $site = $this->on('hub', fn () => Site::create(['slug' => 'lobby', 'name' => 'Lobby', 'driver' => 'mikrotik_ros7']));
        $grant = $this->on('hub', fn () => Grant::create(['type' => GrantType::Code, 'code' => '123456', 'duration_s' => 3600,
            'expiry_mode' => ExpiryMode::FirstLogin]));
        $this->on('hub', fn () => User::factory()->admin()->create(['email' => 'admin@example.com']));
        $this->on('hub', fn () => Channel::create(['kind' => Channel::KIND_NOTIFICATION, 'driver' => 'gotify', 'name' => 'Gotify',
            'config' => ['url' => 'https://gotify.example.com', 'token' => 'secret-token'], 'enabled' => true]));

        $this->assertSame(0, $this->join('new', $this->token()), Artisan::output());

        $hubId = $this->idOf('hub');
        $newId = $this->idOf('new');
        // Hub: the new instance is registered with the pin of its TLS client certificate; the hub is the hub.
        $registered = $this->on('hub', fn () => Instance::find($newId));
        $this->assertSame($this->on('new', fn () => Instance::find($newId)->cert_fingerprint), $registered->cert_fingerprint);
        $this->assertSame('https://new.example.com:9443', $registered->federation_url);
        $this->assertSame($hubId, $this->on('hub', fn () => app(Topology::class)->hubId()));
        $this->assertTrue($this->on('hub', fn () => DB::table('federation_join_tokens')->whereNotNull('used_at')->exists()));

        // New instance: replicated state, data key (secrets readable), cursors after the snapshot.
        $this->on('new', function () use ($site, $grant, $hubId) {
            $this->assertSame('lobby', Site::find($site->id)->slug);
            $this->assertNotNull(Grant::find($grant->id));
            $this->assertTrue(User::where('email', 'admin@example.com')->exists());
            $this->assertSame('secret-token', Channel::first()->config['token']);
            $this->assertSame($hubId, app(Topology::class)->hubId());
            $this->assertFalse(app(Topology::class)->isHub());
            $this->assertNotNull(Instance::find($hubId)->signing_public_key);
            $this->assertNotNull(app(Pairing::class)->isFresh());
        });
        $hubSeq = $this->on('hub', fn () => (int) DB::table(Outbox::TABLE)->where('origin', $hubId)->max('seq'));
        $this->assertSame($hubSeq, $this->on('new', fn () => (int) DB::table('peer_cursors')->where('origin', $hubId)->value('last_seq')));
        $this->assertSame($this->on('hub', fn () => app(DataKey::class)->currentId()), $this->on('new', fn () => app(DataKey::class)->currentId()));
        // A local write after the join sorts after the snapshot (clock position persisted).
        $max = $this->on('new', fn () => DB::table('sites')->max('version'));
        $this->assertGreaterThan($max, $this->on('new', fn () => DB::table(Outbox::TABLE)->where('origin', $newId)->max('version')));
    }

    public function test_join_token_is_single_use_and_expires(): void
    {
        $token = $this->token();
        $this->assertSame(0, $this->join('new', $token), Artisan::output());
        $this->assertSame(1, $this->join('third', $token));
        $this->assertStringContainsString('invalid or expired join token', Artisan::output());
        $this->assertTrue($this->on('third', fn () => app(Pairing::class)->isFresh() === null));

        $expired = $this->token();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(1, $this->join('third', $expired));
        $this->assertStringContainsString('invalid or expired join token', Artisan::output());
        $this->assertNull($this->on('hub', fn () => Instance::find($this->idOf('third'))));
    }

    public function test_only_fresh_instances_can_join(): void
    {
        $this->on('new', fn () => Artisan::call('portal:admin', ['--email' => 'local@example.com', '--name' => 'Local',
            '--password' => 'a-long-password-1', '--no-interaction' => true]));
        $this->assertSame(1, $this->join('new', $this->token()));
        $this->assertStringContainsString('table users is not empty', Artisan::output());

        $this->on('third', fn () => Site::create(['slug' => 'x', 'name' => 'X', 'driver' => 'mikrotik_ros7']));
        $this->assertSame('table sites is not empty', $this->on('third', fn () => app(Pairing::class)->isFresh()));
        $this->assertSame(1, $this->join('third', $this->token(), 'not-a-pin'));
        $this->assertNull($this->on('hub', fn () => Instance::find($this->idOf('new'))));
    }

    public function test_join_endpoint_rejects_wrong_protocol_and_known_certificates(): void
    {
        $token = $this->token();
        $pem = $this->certificateOf('hub');
        $this->on('hub', function () use ($token, $pem) {
            $body = ['token' => $token, 'protocol' => 99, 'instance' => ['id' => (string) Str::ulid(), 'name' => 'X',
                'federation_url' => 'https://x.example.com:9443', 'signing_public_key' => base64_encode(random_bytes(32))]];
            $this->asPeer(null)->postJson('/federation/join', $body)->assertForbidden();
            $this->asPeer($pem)->postJson('/federation/join', $body)->assertStatus(409);
            // The hub's own certificate is already registered.
            $this->asPeer($pem)->postJson('/federation/join', ['protocol' => 1] + $body)->assertStatus(409)
                ->assertJson(['error' => 'certificate already registered']);
            $this->assertNull(DB::table('federation_join_tokens')->value('used_at'));
        });
    }

    public function test_admin_page_token_hub_setting_unpause_and_remove_peer(): void
    {
        $this->assertSame(0, $this->join('new', $this->token()), Artisan::output());
        $newId = $this->idOf('new');

        $this->on('hub', function () use ($newId) {
            $admin = User::factory()->admin()->create();
            $this->actingAs($admin);
            Channel::create(['kind' => Channel::KIND_NOTIFICATION, 'driver' => 'telegram', 'name' => 'Team bot',
                'config' => ['bot_token' => '1:x', 'chat_id' => '-1', 'allowed_users' => '1'], 'enabled' => true]);
            FederationPeer::for($newId)->fill(['last_seen_at' => now(), 'clock_skew_ms' => 45000, 'protocol' => 1,
                'paused_at' => now(), 'pause_reason' => 'clock skew 301 s'])->save();

            $this->onZone('admin')->get('/admin/federation')->assertOk()
                ->assertSee('New')->assertSee($this->hubPin)->assertSee(__('portal.federation.skew_warning'))
                ->assertSee('clock skew 301 s');

            $this->onZone('admin')->post('/admin/federation/tokens')->assertRedirect()->assertSessionHas('federation_join');
            $this->assertSame(2, DB::table('federation_join_tokens')->count());

            $this->onZone('admin')->put('/admin/federation/settings', ['hub' => $newId, 'offline_sms_only' => '0'])->assertRedirect();
            $this->assertSame($newId, app(Topology::class)->hubId());
            $this->assertFalse(app(Topology::class)->offlineSmsOnly());
            // The hub cannot be removed.
            $this->onZone('admin')->delete("/admin/federation/peers/$newId")->assertSessionHasErrors('federation');
            $this->onZone('admin')->put('/admin/federation/settings', ['hub' => app(LocalInstance::class)->id(), 'offline_sms_only' => '1']);

            $this->onZone('admin')->post("/admin/federation/peers/$newId/unpause")->assertRedirect();
            $this->assertNull(FederationPeer::find($newId)->paused_at);

            $kid = app(DataKey::class)->currentId();
            $this->onZone('admin')->delete("/admin/federation/peers/$newId")->assertRedirect('/admin/federation')
                ->assertSessionHas('federation_checklist', fn (array $items) => str_contains(implode(' ', $items), 'Team bot'));
            $this->assertSoftDeleted('instances', ['id' => $newId]);
            $this->assertNotSame($kid, app(DataKey::class)->currentId());
            $this->assertSame('1:x', Channel::first()->config['bot_token']);
            $this->assertTrue(AuditLog::where('action', 'federation.peer_removed')->exists());
            $this->assertTrue(Setting::get(Topology::OFFLINE_SMS_ONLY_SETTING));
        });
    }
}
