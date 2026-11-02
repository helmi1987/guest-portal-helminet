<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\Applier;
use App\Federation\EventCodec;
use App\Federation\Outbox;
use App\Federation\Pairing;
use App\Federation\Topology;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\Instance;
use App\Models\NasClient;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Federation review fixes: a compromised member cannot take over the membership (trust anchors of
 * other instances, registrations, removals, hub setting); one stuck origin does not starve a push.
 */
class AuthorityTest extends TestCase
{
    use FederatedHttp, RefreshDatabase, SimulatesInstances;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 10:00:00');
        $this->makeInstances('hub', 'a', 'b');
        $pin = $this->giveCertificate('hub');
        $this->giveCertificate('a');
        $this->giveCertificate('b');
        $this->routeFederation(['hub', 'a', 'b']);
        foreach (['a', 'b'] as $spoke) {
            $token = $this->on('hub', fn () => app(Pairing::class)->createToken(null)['token']);
            $this->assertSame(0, $this->on($spoke, fn () => Artisan::call('portal:federation:join', [
                'url' => 'https://hub.example.com:9443', 'token' => $token, 'fingerprint' => $pin])), Artisan::output());
        }
        $this->syncRound();
    }

    private function syncRound(): void
    {
        foreach (['hub', 'a', 'b', 'hub', 'a', 'b'] as $name) {
            $this->on($name, fn () => Artisan::call('portal:federation:sync'));
        }
    }

    /** A raw write on b recorded as b's (validly signed) event: what a compromised instance can send. */
    private function forgeOnB(string $table, string $id, array $values, bool $insert = false): void
    {
        $this->on('b', function () use ($table, $id, $values, $insert) {
            DB::transaction(function () use ($table, $id, $values, $insert) {
                $values['version'] = app(Hlc::class)->now();
                $insert ? DB::table($table)->insert(['id' => $id] + $values) : DB::table($table)->where('id', $id)->update($values);
                app(Outbox::class)->record($table, $id);
            });
        });
        $this->syncRound();
    }

    public function test_trust_anchors_of_an_instance_change_only_by_its_own_events(): void
    {
        $hubId = $this->idOf('hub');
        $original = $this->rowOn('a', 'instances', $hubId);
        $this->forgeOnB('instances', $hubId, ['signing_public_key' => base64_encode(random_bytes(32)),
            'cert_fingerprint' => 'sha256//'.base64_encode(random_bytes(32)), 'federation_url' => 'https://attacker.example.com:9443',
            'admin_url' => 'https://attacker.example.com', 'name' => 'Renamed']);

        foreach (['hub', 'a'] as $name) {
            $row = $this->rowOn($name, 'instances', $hubId);
            foreach (['signing_public_key', 'cert_fingerprint', 'federation_url', 'admin_url'] as $column) {
                $this->assertSame($original[$column], $row[$column], "$name $column");
            }
        }
        // Replication keeps working with the genuine keys.
        $grant = $this->on('hub', fn () => Grant::create(['type' => GrantType::Code, 'code' => '123456', 'duration_s' => 60,
            'expiry_mode' => ExpiryMode::FirstLogin]));
        $this->syncRound();
        $this->assertNotNull($this->rowOn('a', 'grants', $grant->id));

        // An instance may change its own URLs (b trusts nothing anymore: it broke its own copy).
        $idA = $this->idOf('a');
        $this->on('a', fn () => Instance::find($idA)->update(['federation_url' => 'https://a-new.example.com:9443']));
        $this->syncRound();
        $this->assertSame('https://a-new.example.com:9443', $this->rowOn('hub', 'instances', $idA)['federation_url']);
    }

    public function test_router_api_access_is_local_to_the_instance(): void
    {
        $site = $this->on('hub', fn () => Site::create(['slug' => 's', 'name' => 'S', 'driver' => 'mikrotik_ros7',
            'home_instance_id' => app(LocalInstance::class)->id()]));
        $nas = $this->on('hub', fn () => $site->nasClients()->create(['name' => 'R', 'nas_ip' => '10.0.2.1', 'coa_port' => 3799,
            'api_host' => '10.0.2.1', 'api_type' => 'rest', 'api_port' => 443, 'api_user' => 'ro-user', 'api_password' => 'Hub secret 1', 'api_pin' => 'sha256//abc']));
        $this->syncRound();

        // Not sent: no payload carries api_* and the replica has none
        $payloads = DB::table(Outbox::TABLE)->where('table', 'nas_clients')->pluck('payload')->implode("\n");
        $this->assertStringNotContainsString('Hub secret 1', $payloads);
        $this->assertStringNotContainsString('ro-user', $payloads);
        $this->assertStringNotContainsString('api_host', $payloads);
        $row = $this->rowOn('a', 'nas_clients', $nas->id);
        $this->assertSame('R', $row['name']);
        foreach (['api_host', 'api_type', 'api_port', 'api_user', 'api_password', 'api_pin'] as $column) {
            $this->assertNull($row[$column], $column);
        }

        // Local values on a survive an incoming update from the hub
        $this->on('a', fn () => NasClient::find($nas->id)->forceFill(['api_host' => '10.9.9.9', 'api_type' => 'api-ssl',
            'api_user' => 'a-user', 'api_password' => 'A secret 1'])->saveQuietly());
        $this->on('hub', fn () => NasClient::find($nas->id)->update(['name' => 'R renamed']));
        $this->syncRound();
        $this->assertSame('R renamed', $this->rowOn('a', 'nas_clients', $nas->id)['name']);
        $a = $this->on('a', fn () => NasClient::find($nas->id)->only(['api_host', 'api_type', 'api_user', 'api_password']));
        $this->assertSame(['api_host' => '10.9.9.9', 'api_type' => 'api-ssl', 'api_user' => 'a-user', 'api_password' => 'A secret 1'], $a);
        $this->assertSame('Hub secret 1', $this->on('hub', fn () => NasClient::find($nas->id)->api_password));
    }

    public function test_local_wifi_password_is_never_replicated_nor_overwritten_by_remote_events(): void
    {
        $setup = ['ssid' => 'Guest', 'wifi_security' => 'wpa', 'wifi_password_shared' => false];
        $site = $this->on('hub', fn () => Site::create(['slug' => 's', 'name' => 'S', 'driver' => 'mikrotik_ros7',
            'home_instance_id' => app(LocalInstance::class)->id(), 'settings' => ['setup' => $setup]]));
        $this->syncRound();
        $local = fn (string $name) => $this->on($name, fn () => Site::find($site->id)->wifi_password_local);
        $this->on('a', fn () => Site::find($site->id)->forceFill(['wifi_password_local' => 'A local pass 1'])->save());
        $this->on('hub', fn () => Site::find($site->id)->forceFill(['wifi_password_local' => 'Hub local pass 1'])->save());
        $this->syncRound();

        // Remote updates without the column keep each instance's own value
        $this->on('hub', fn () => Site::find($site->id)->update(['name' => 'S renamed']));
        $this->syncRound();
        $this->assertSame('S renamed', $this->rowOn('a', 'sites', $site->id)['name']);
        $this->assertSame('A local pass 1', $local('a'));
        $this->assertSame('Hub local pass 1', $local('hub'));
        $this->assertNull($local('b'));
        foreach (['hub', 'a'] as $name) {
            $payloads = $this->on($name, fn () => DB::table(Outbox::TABLE)->where('table', 'sites')->pluck('payload')->implode("\n"));
            $this->assertStringNotContainsString('wifi_password_local', $payloads, $name);
        }

        // An event that carries the column anyway (forged or a future peer) does not touch it
        $idHub = $this->idOf('hub');
        $result = $this->on('a', function () use ($site, $idHub) {
            $row = (array) DB::table('sites')->where('id', $site->id)->first();
            $seq = (int) DB::table('peer_cursors')->where('origin', $idHub)->value('last_seq') + 1;

            return app(Applier::class)->apply($idHub, [['origin' => $idHub, 'seq' => $seq, 'table' => 'sites', 'record_id' => $site->id,
                'version' => Hlc::format(Hlc::wallMs() + 60000, 0, $idHub), 'payload' => array_merge($row, ['name' => 'S forged',
                    'wifi_password_local' => null, 'version' => null])]]);
        });
        $this->assertSame(1, $result->applied);
        $this->assertSame('S forged', $this->rowOn('a', 'sites', $site->id)['name']);
        $this->assertSame('A local pass 1', $local('a'));
    }

    public function test_a_replicated_shared_flag_without_a_value_keeps_local_wifi_passwords(): void
    {
        $setup = ['ssid' => 'Guest', 'wifi_security' => 'wpa', 'wifi_password_shared' => false];
        $site = $this->on('hub', fn () => Site::create(['slug' => 's', 'name' => 'S', 'driver' => 'mikrotik_ros7',
            'home_instance_id' => app(LocalInstance::class)->id(), 'settings' => ['setup' => $setup]]));
        $this->syncRound();
        $this->on('a', fn () => Site::find($site->id)->forceFill(['wifi_password_local' => 'A only pass 1'])->save());
        $this->syncRound();

        // The hub sets the flag without a shared value: a keeps its local password (the only copy)
        $this->on('hub', fn () => Site::find($site->id)->update(['settings' => ['setup' => ['wifi_password_shared' => true] + $setup]]));
        $this->syncRound();
        $this->assertTrue($this->on('a', fn () => Site::find($site->id)->wifiPasswordShared()));
        $this->assertSame('A only pass 1', $this->on('a', fn () => Site::find($site->id)->localWifiPassword()));
        $this->assertSame(0, $this->on('a', fn () => Site::dropSharedLocalWifiPasswords()));
        $this->assertSame('A only pass 1', $this->on('a', fn () => Site::find($site->id)->localWifiPassword()));
    }

    public function test_switching_a_site_to_a_shared_wifi_password_drops_local_copies_everywhere(): void
    {
        $setup = ['ssid' => 'Guest', 'wifi_security' => 'wpa', 'wifi_password_shared' => false];
        $site = $this->on('hub', fn () => Site::create(['slug' => 's', 'name' => 'S', 'driver' => 'mikrotik_ros7',
            'home_instance_id' => app(LocalInstance::class)->id(), 'settings' => ['setup' => $setup]]));
        $this->syncRound();
        $this->on('a', fn () => Site::find($site->id)->forceFill(['wifi_password_local' => 'A old pass 1'])->save());
        $this->on('hub', fn () => Site::find($site->id)->forceFill(['wifi_password_local' => 'Hub pass 1'])->save());
        $this->syncRound();
        $this->assertSame('A old pass 1', $this->on('a', fn () => Site::find($site->id)->wifiPassword()));

        // The hub shares its password: the hub keeps no local copy, a drops its stale one and uses the shared one
        $this->on('hub', function () use ($site, $setup) {
            $s = Site::find($site->id);
            $s->update(['settings' => ['setup' => ['wifi_password_shared' => true,
                'wifi_password' => Site::encryptWifiPassword($s->wifiPassword())] + $setup]]);
        });
        $this->assertNull($this->rowOn('hub', 'sites', $site->id)['wifi_password_local']);
        $this->syncRound();
        $this->assertNull($this->rowOn('a', 'sites', $site->id)['wifi_password_local']);
        $this->assertSame('Hub pass 1', $this->on('a', fn () => Site::find($site->id)->wifiPassword()));
        $this->assertSame('Hub pass 1', $this->on('b', fn () => Site::find($site->id)->wifiPassword()));

        // Not shared again (value moved to the hub's local column): a has no password, it does not come back
        $this->on('hub', function () use ($site, $setup) {
            $s = Site::find($site->id);
            $s->forceFill(['settings' => ['setup' => $setup], 'wifi_password_local' => $s->wifiPassword()])->save();
        });
        $this->syncRound();
        $this->assertSame('Hub pass 1', $this->on('hub', fn () => Site::find($site->id)->wifiPassword()));
        $this->assertTrue($this->on('a', fn () => Site::find($site->id)->wifiPasswordUnavailable()));
    }

    public function test_only_the_hub_registers_and_removes_instances(): void
    {
        $idA = $this->idOf('a');
        $this->forgeOnB('instances', $idA, ['deleted_at' => now()->toDateTimeString()]);
        $this->assertNull($this->rowOn('hub', 'instances', $idA)['deleted_at']);
        $this->assertNull($this->rowOn('a', 'instances', $idA)['deleted_at']);

        $fake = '01JFAKE0000000000000000000';
        $this->forgeOnB('instances', $fake, ['name' => 'Fake', 'origin_instance_id' => $fake,
            'signing_public_key' => base64_encode(random_bytes(32)), 'created_at' => now(), 'updated_at' => now()], insert: true);
        $this->assertNull($this->rowOn('hub', 'instances', $fake));
        $this->assertNull($this->rowOn('a', 'instances', $fake));

        // The hub's removal replicates.
        $idB = $this->idOf('b');
        $this->on('hub', fn () => Instance::find($idB)->delete());
        $this->syncRound();
        $this->assertNotNull($this->rowOn('a', 'instances', $idB)['deleted_at']);
    }

    public function test_hub_setting_changes_only_by_the_hub(): void
    {
        $hubId = $this->idOf('hub');
        $this->forgeOnB('settings', Setting::idFor(Topology::HUB_SETTING), ['value' => json_encode($this->idOf('b'))]);
        $this->assertSame($hubId, $this->on('hub', fn () => app(Topology::class)->hubId()));
        $this->assertSame($hubId, $this->on('a', fn () => app(Topology::class)->hubId()));

        // The settings form of a non-hub instance cannot hand the role over either.
        $this->on('a', function () use ($hubId) {
            $this->actingAs(User::factory()->admin()->create());
            $this->onZone('admin')->put('/admin/federation/settings', ['hub' => app(LocalInstance::class)->id(),
                'offline_sms_only' => '1'])->assertSessionHasErrors('hub');
            $this->assertSame($hubId, app(Topology::class)->hubId());
        });
    }

    public function test_only_the_hub_creates_or_promotes_admins(): void
    {
        $idB = $this->idOf('b');
        // b creates an admin and a manager and promotes an existing manager; the grant after them still applies.
        $manager = $this->on('hub', fn () => User::factory()->create(['email' => 'manager@example.com']));
        $this->syncRound();
        [$admin, $other, $grant] = $this->on('b', fn () => [
            User::factory()->admin()->create(['email' => 'rogue@example.com']),
            User::factory()->create(['email' => 'helper@example.com']),
            Grant::create(['type' => GrantType::Code, 'code' => '444444', 'duration_s' => 60, 'expiry_mode' => ExpiryMode::FirstLogin]),
        ]);
        $this->on('b', fn () => User::find($manager->id)->update(['role' => 'admin']));
        $this->syncRound();

        foreach (['hub', 'a'] as $name) {
            $this->assertNull($this->rowOn($name, 'users', $admin->id), $name);
            $this->assertSame('manager', $this->rowOn($name, 'users', $manager->id)['role'], $name);
            $this->assertNotNull($this->rowOn($name, 'users', $other->id), $name);
            $this->assertNotNull($this->rowOn($name, 'grants', $grant->id), $name);
            $refused = $this->on($name, fn () => AuditLog::where('action', 'federation.event_refused')->where('subject_id', $admin->id)->sole());
            $this->assertSame($idB, $refused->data['origin']);
            $this->assertSame('users', $refused->data['table']);
        }
        $this->assertSame(0, $this->on('hub', fn () => DB::table('peer_cursors')->whereNotNull('paused_at')->count()));

        // The same from the hub is applied everywhere.
        $hubAdmin = $this->on('hub', fn () => User::factory()->admin()->create(['email' => 'second-admin@example.com']));
        $this->on('hub', fn () => User::find($manager->id)->update(['role' => 'admin']));
        $this->syncRound();
        foreach (['a', 'b'] as $name) {
            $this->assertSame('admin', $this->rowOn($name, 'users', $hubAdmin->id)['role'], $name);
            $this->assertSame('admin', $this->rowOn($name, 'users', $manager->id)['role'], $name);
        }
        // Other changes of an existing admin by a site still replicate.
        $this->on('b', fn () => User::find($hubAdmin->id)->update(['name' => 'Renamed']));
        $this->syncRound();
        $this->assertSame('Renamed', $this->rowOn('a', 'users', $hubAdmin->id)['name']);
    }

    public function test_a_deleted_admin_is_restored_only_by_the_hub(): void
    {
        $admin = $this->on('hub', fn () => User::factory()->admin()->create(['email' => 'old-admin@example.com']));
        $this->on('hub', fn () => User::factory()->admin()->create());
        $this->syncRound();
        $this->on('hub', fn () => User::find($admin->id)->delete());
        $this->syncRound();
        $this->assertNotNull($this->rowOn('a', 'users', $admin->id)['deleted_at']);

        $this->forgeOnB('users', $admin->id, ['deleted_at' => null]);
        $this->assertNotNull($this->rowOn('hub', 'users', $admin->id)['deleted_at']);
        $this->assertNotNull($this->rowOn('a', 'users', $admin->id)['deleted_at']);
    }

    public function test_security_settings_change_only_by_the_hub(): void
    {
        $this->on('hub', fn () => Setting::put('require_2fa', true));
        $this->syncRound();
        $this->assertTrue($this->on('a', fn () => Setting::get('require_2fa')));

        $this->forgeOnB('settings', Setting::idFor('require_2fa'), ['value' => json_encode(false)]);
        $this->on('b', function () {
            Setting::put('oidc', ['enabled' => true, 'issuer' => 'https://idp.attacker.example.com']);
            Setting::put('sms_duration_s', 7200);
        });
        $this->syncRound();
        foreach (['hub', 'a'] as $name) {
            $this->assertTrue($this->on($name, fn () => Setting::get('require_2fa')), $name);
            $this->assertNull($this->on($name, fn () => Setting::get('oidc')), $name);
            // Settings outside the list keep replicating from every instance.
            $this->assertSame(7200, $this->on($name, fn () => Setting::get('sms_duration_s')), $name);
            $this->assertSame(2, $this->on($name, fn () => AuditLog::where('action', 'federation.event_refused')->count()), $name);
        }

        // The hub's change is applied everywhere, b included.
        $this->on('hub', fn () => Setting::put('oidc', ['enabled' => true, 'issuer' => 'https://idp.example.com']));
        $this->syncRound();
        foreach (['a', 'b'] as $name) {
            $this->assertSame('https://idp.example.com', $this->on($name, fn () => Setting::get('oidc')['issuer']), $name);
        }
    }

    public function test_hotspot_hosts_of_a_site_change_only_by_the_hub_or_its_home_instance(): void
    {
        $idA = $this->idOf('a');
        $site = $this->on('a', fn () => Site::create(['slug' => 'sa', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'home_instance_id' => $idA, 'settings' => ['hotspot_hosts' => ['hotspot-a.example.com']]]));
        $this->syncRound();
        $this->assertStringContainsString('hotspot-a.example.com', $this->rowOn('hub', 'sites', $site->id)['settings']);

        // A rename by b (neither hub nor home) applies; pointing the hand-off to its own host is dropped.
        $this->on('b', fn () => Site::find($site->id)->update(['name' => 'Renamed by b']));
        $this->syncRound();
        $this->assertSame('Renamed by b', $this->rowOn('a', 'sites', $site->id)['name']);
        $this->on('b', fn () => Site::find($site->id)->update(['settings' => ['hotspot_hosts' => ['attacker.example.com']]]));
        $this->syncRound();
        foreach (['hub', 'a'] as $name) {
            $this->assertSame(['hotspot-a.example.com'], $this->on($name, fn () => Site::find($site->id)->setting('hotspot_hosts')), $name);
            $this->assertTrue($this->on($name, fn () => AuditLog::where('action', 'federation.event_refused')->where('subject_id', $site->id)->exists()));
        }

        // The home instance and the hub may.
        $this->on('a', fn () => Site::find($site->id)->update(['settings' => ['hotspot_hosts' => ['login-a.example.com']]]));
        $this->syncRound();
        $this->assertSame(['login-a.example.com'], $this->on('hub', fn () => Site::find($site->id)->setting('hotspot_hosts')));
        $this->on('hub', fn () => Site::find($site->id)->update(['settings' => ['hotspot_hosts' => ['hub-set.example.com'],
            'portal_url' => 'https://portal.example.com']]));
        $this->syncRound();
        $this->assertSame('https://portal.example.com', $this->on('a', fn () => Site::find($site->id)->setting('portal_url')));
        $this->assertSame(['hub-set.example.com'], $this->on('b', fn () => Site::find($site->id)->setting('hotspot_hosts')));
    }

    public function test_a_stuck_origin_does_not_starve_the_push_of_other_origins(): void
    {
        config(['portal.federation.batch' => 2]);
        // Validly signed events of an origin b cannot verify (key unknown), sorting before a's.
        $pair = sodium_crypto_sign_keypair();
        $stuck = '00000000000000000000000000';
        $this->on('hub', function () use ($pair, $stuck) {
            for ($seq = 1; $seq <= 3; $seq++) {
                $id = sprintf('01JSTUCK%018d', $seq);
                $version = Hlc::format(Hlc::wallMs(), $seq, $stuck);
                $payload = json_encode(['id' => $id, 'first_name' => 'X', 'last_name' => 'Y', 'version' => $version]);
                DB::table(Outbox::TABLE)->insert(['origin' => $stuck, 'seq' => $seq, 'table' => 'guests', 'record_id' => $id,
                    'version' => $version, 'payload' => $payload, 'proto' => 1, 'created_at' => now(),
                    'sig' => base64_encode(sodium_crypto_sign_detached(EventCodec::message($stuck, $seq, 'guests', $id,
                        $version, 1, $payload), sodium_crypto_sign_secretkey($pair)))]);
            }
        });
        $grant = $this->on('a', fn () => Grant::create(['type' => GrantType::Code, 'code' => '222222', 'duration_s' => 60,
            'expiry_mode' => ExpiryMode::FirstLogin]));
        $this->assertNotNull($this->rowOn('b', 'grants', $grant->id));
    }
}
