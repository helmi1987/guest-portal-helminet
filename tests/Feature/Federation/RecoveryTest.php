<?php

namespace Tests\Feature\Federation;

use App\Federation\DataKey;
use App\Federation\Outbox;
use App\Federation\Pairing;
use App\Models\AuditLog;
use App\Models\CertificateSource;
use App\Models\Instance;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use App\Radius\ClientsFile;
use App\Support\Hlc;
use App\Support\LocalInstance;
use App\Tls\CertificateSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Replicated routers and certificate sources (recovery): only the home instance serves a site's
 * routers, a replacement takes a site and a certificate source over, secrets never travel in plain.
 */
class RecoveryTest extends TestCase
{
    use FederatedHttp, RefreshDatabase, SimulatesInstances;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 10:00:00');
    }

    private function federation(): void
    {
        $this->makeInstances('hub', 'a');
        $pin = $this->giveCertificate('hub');
        $this->giveCertificate('a');
        $this->routeFederation(['hub', 'a']);
        $token = $this->on('hub', fn () => app(Pairing::class)->createToken(null)['token']);
        $this->assertSame(0, $this->on('a', fn () => Artisan::call('portal:federation:join', [
            'url' => 'https://hub.example.com:9443', 'token' => $token, 'fingerprint' => $pin])), Artisan::output());
    }

    private function syncRound(): void
    {
        foreach (['hub', 'a', 'hub', 'a'] as $name) {
            $this->on($name, fn () => Artisan::call('portal:federation:sync'));
        }
    }

    public function test_clients_file_and_radius_only_for_sites_served_here(): void
    {
        $other = Instance::create(['name' => 'Other']);
        $mine = Site::create(['slug' => 'mine', 'name' => 'Mine', 'driver' => 'mikrotik_ros7']);
        $theirs = Site::create(['slug' => 'theirs', 'name' => 'Theirs', 'driver' => 'mikrotik_ros7', 'home_instance_id' => $other->id]);
        $own = $mine->nasClients()->create(['name' => 'R1', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
        $foreign = $theirs->nasClients()->create(['name' => 'R2', 'nas_ip' => '10.0.1.1', 'coa_port' => 3799]);

        $file = app(ClientsFile::class)->render();
        $this->assertStringContainsString($own->shortname(), $file);
        $this->assertStringContainsString($own->radius_secret, $file);
        $this->assertStringNotContainsString($foreign->shortname(), $file);

        $body = fn (NasClient $nas) => array_map(fn ($v) => ['type' => 'string', 'value' => [$v]], ['User-Name' => 'AA:BB:CC:00:00:01',
            'User-Password' => '', 'Calling-Station-Id' => 'AA:BB:CC:00:00:01', 'FreeRADIUS-Client-Shortname' => $nas->shortname()]);
        $this->onZone('internal')->withHeader('Authorization', 'Basic '.base64_encode('radius:test-internal-token'))
            ->postJson('/internal/radius/authorize', $body($foreign))->assertStatus(401);
        // Downloads by routers of other instances' sites are refused too.
        $this->onZone('guest')->withHeader('X-Portal-Token', $foreign->certToken())->get("/router-login/{$foreign->id}/login.html")
            ->assertForbidden();
    }

    public function test_clients_file_never_renders_unsafe_router_values(): void
    {
        $site = Site::create(['slug' => 'mine', 'name' => 'Mine', 'driver' => 'mikrotik_ros7']);
        $good = $site->nasClients()->create(['name' => 'Good', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
        $v6 = $site->nasClients()->create(['name' => 'V6', 'nas_ip' => '2001:db8::1', 'coa_port' => 3799]);
        $net = $site->nasClients()->create(['name' => 'Net', 'nas_ip' => '10.0.9.0/24', 'coa_port' => 3799]);
        $crafted = [
            ['nas_ip' => "10.0.0.5\n}\nclient 0.0.0.0/0 {\n secret = \"x\"\n ipaddr = 0.0.0.0/0"],
            ['nas_ip' => '0.0.0.0/0'],
            ['nas_ip' => '10.0.0.6 '],
            ['nas_ip' => 'radius.example.com'],
            ['radius_secret' => "abc\"\n}\nclient 0.0.0.0/0 {"],
            ['radius_secret' => 'with space'],
            ['radius_secret' => 'brace{'],
            ['radius_secret' => 'hash#tag'],
            ['radius_secret' => 'back\\slash'],
            ['radius_secret' => ''],
            ['id' => '01JBAD ID {}0000000000000'],
        ];
        $bad = [];
        foreach ($crafted as $i => $values) {
            $nas = (new NasClient(['name' => "Bad $i", 'nas_ip' => '10.0.1.'.($i + 1), 'coa_port' => 3799]))->forceFill($values);
            $nas->site()->associate($site)->save();
            $bad[] = $nas->getKey();
        }

        $file = app(ClientsFile::class)->render();
        foreach ([$good, $v6, $net] as $nas) {
            $this->assertStringContainsString('client '.$nas->shortname().' {', $file);
        }
        $this->assertStringContainsString('ipaddr = 2001:db8::1', $file);
        $this->assertStringContainsString('ipaddr = 10.0.9.0/24', $file);
        foreach ($bad as $id) {
            $this->assertStringNotContainsString("nas-$id", $file);
        }
        $this->assertStringNotContainsString('0.0.0.0/0', $file);
        $this->assertSame(3, substr_count($file, 'client nas-'));
    }

    public function test_replicated_router_rows_with_unsafe_values_are_refused(): void
    {
        $this->federation();
        $site = $this->on('hub', fn () => Site::create(['slug' => 's', 'name' => 'S', 'driver' => 'mikrotik_ros7']));
        $nas = $this->on('hub', fn () => $site->nasClients()->create(['name' => 'R', 'nas_ip' => '10.0.2.1', 'coa_port' => 3799]));
        $this->syncRound();

        // Validly signed by the hub, but written without the admin form: dropped on a, the batch continues.
        $this->on('hub', function () use ($nas) {
            DB::transaction(function () use ($nas) {
                DB::table('nas_clients')->where('id', $nas->id)->update(['nas_ip' => "10.0.2.1\n}\nclient 0.0.0.0/0 {",
                    'version' => app(Hlc::class)->now()]);
                app(Outbox::class)->record('nas_clients', $nas->id);
            });
        });
        $later = $this->on('hub', fn () => $site->nasClients()->create(['name' => 'Later', 'nas_ip' => '10.0.2.2', 'coa_port' => 3799]));
        $this->syncRound();

        $this->assertSame('10.0.2.1', $this->rowOn('a', 'nas_clients', $nas->id)['nas_ip']);
        $this->assertNotNull($this->rowOn('a', 'nas_clients', $later->id));
        $this->assertTrue($this->on('a', fn () => AuditLog::where('action', 'federation.event_refused')->where('subject_id', $nas->id)->exists()));
    }

    public function test_replacement_takes_over_a_site_and_a_certificate_source(): void
    {
        $this->federation();
        $site = $this->on('hub', fn () => Site::create(['slug' => 's', 'name' => 'S', 'driver' => 'mikrotik_ros7',
            'home_instance_id' => app(LocalInstance::class)->id()]));
        $nas = $this->on('hub', fn () => $site->nasClients()->create(['name' => 'R', 'nas_ip' => '10.0.2.1', 'coa_port' => 3799]));
        $secret = $this->on('hub', fn () => NasClient::find($nas->id)->radius_secret);
        $this->on('hub', fn () => CertificateSources::put('router', 'url', 'https://pem.example.com/router.pem', null, null, 'api-secret-1'));
        $this->syncRound();

        // Replicated, but a does not serve it yet.
        $this->assertSame($secret, $this->on('a', fn () => NasClient::find($nas->id)->radius_secret));
        $this->assertStringNotContainsString($nas->shortname(), $this->on('a', fn () => app(ClientsFile::class)->render()));

        $hubId = $this->idOf('hub');
        $this->on('a', function () use ($site, $hubId) {
            $this->actingAs(User::factory()->admin()->create());
            $this->onZone('admin')->get("/admin/sites/{$site->id}/edit")->assertOk()->assertSee(__('portal.sites.take_over'));
            $this->onZone('admin')->post("/admin/sites/{$site->id}/take-over")->assertSessionHas('status', __('portal.sites.taken_over'));
            $this->assertTrue(AuditLog::where('action', 'site.taken_over')->exists());

            $this->onZone('admin')->get('/admin/certificates')->assertOk()->assertSee(__('portal.certificates.copy_from'));
            $this->onZone('admin')->post('/admin/certificates/router/copy', ['instance' => $hubId])->assertSessionHas('status');
            $this->assertSame('api-secret-1', CertificateSources::apiKey('router'));
            $this->assertSame('https://pem.example.com/router.pem', CertificateSources::get('router')['url']);
        });
        $this->assertStringContainsString($nas->shortname(), $this->on('a', fn () => app(ClientsFile::class)->render()));
        $this->assertStringContainsString($secret, $this->on('a', fn () => app(ClientsFile::class)->render()));

        // The old home stops serving it after replication.
        $this->syncRound();
        $this->assertStringNotContainsString($nas->shortname(), $this->on('hub', fn () => app(ClientsFile::class)->render()));
        // Each instance still pulls only with its own source.
        $this->assertSame('api-secret-1', $this->on('hub', fn () => CertificateSources::apiKey('router')));
        $this->assertSame(2, $this->on('hub', fn () => CertificateSource::count()));
    }

    public function test_secrets_and_local_status_never_travel_in_plain(): void
    {
        $site = Site::create(['slug' => 's', 'name' => 'S', 'driver' => 'mikrotik_ros7']);
        $nas = $site->nasClients()->create(['name' => 'R', 'nas_ip' => '10.0.3.1', 'coa_port' => 3799]);
        $nas->markSeen();
        CertificateSources::put('portal', 'certwarden', 'https://certs.example.com', 'portal', null, 'cw-key.cw-key2');
        $nas->refresh();

        $payloads = DB::table(Outbox::TABLE)->whereIn('table', ['nas_clients', 'certificate_sources'])->pluck('payload')->implode("\n");
        foreach ([$nas->radius_secret, $nas->certToken(), 'cw-key.cw-key2'] as $secret) {
            $this->assertStringNotContainsString($secret, $payloads);
        }
        $this->assertStringContainsString('fdk1:', $payloads);
        $this->assertStringNotContainsString('last_seen_at', $payloads);
        $this->assertNotNull($nas->last_seen_at);
    }

    public function test_legacy_router_ids_and_data_migration(): void
    {
        $this->withoutFederationWriteGuard();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $files = collect(glob(database_path('migrations/*.php')))->sort()->values();
        $last = $files->search(fn ($f) => str_contains($f, 'replicate_nas_clients_and_certificate_sources'));
        Artisan::call('migrate', ['--database' => 'legacy', '--path' => $files->take($last)->all(), '--realpath' => true, '--force' => true]);

        $db = DB::connection('legacy');
        $siteId = strtolower((string) Str::ulid());
        $db->table('sites')->insert(['id' => $siteId, 'slug' => 'old', 'name' => 'Old', 'driver' => 'mikrotik_ros7', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('nas_clients')->insert(['id' => 7, 'site_id' => $siteId, 'name' => 'Old router', 'nas_ip' => '10.0.9.1',
            'radius_secret' => Crypt::encryptString('old-radius-secret'), 'cert_token' => Crypt::encryptString('old-token'),
            'coa_port' => 3799, 'created_at' => now(), 'updated_at' => now()]);
        $summary = strtolower((string) Str::ulid());
        $db->table('session_summaries')->insert(['id' => $summary, 'site_id' => $siteId, 'mac' => 'AA:BB:CC:00:00:09', 'started_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $db->table('radius_accounting')->insert(['session_summary_id' => $summary, 'nas_client_id' => 7, 'acct_session_id' => 'x1',
            'username' => 'u', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('local_settings')->insert(['key' => 'cert_source.router', 'value' => json_encode(['type' => 'url',
            'url' => 'https://pem.example.com/r.pem', 'api_key' => Crypt::encryptString('old-api-key')]), 'created_at' => now(), 'updated_at' => now()]);

        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('legacy');
        app()->forgetInstance(LocalInstance::class);
        app()->forgetInstance(Outbox::class);
        app()->forgetInstance(DataKey::class);
        app()->forgetInstance(Hlc::class);
        try {
            Artisan::call('migrate', ['--database' => 'legacy', '--force' => true]);
            $nas = NasClient::query()->sole();
            $this->assertSame(7, $nas->legacy_id);
            $this->assertSame(26, strlen($nas->id));
            $this->assertSame('old-radius-secret', $nas->radius_secret);
            $this->assertStringStartsWith('fdk1:', $nas->getRawOriginal('radius_secret'));
            $this->assertSame($nas->id, DB::table('radius_accounting')->value('nas_client_id'));
            $this->assertTrue(DB::table(Outbox::TABLE)->where('table', 'nas_clients')->where('record_id', $nas->id)->exists());
            $this->assertSame('old-api-key', CertificateSources::apiKey('router'));
            $this->assertNull(DB::table('local_settings')->where('key', 'cert_source.router')->first());
            // Deployed router scripts keep working with the old number.
            $this->assertSame($nas->id, NasClient::findServed('7')?->id);
            $this->assertSame($nas->id, NasClient::findServed($nas->id)?->id);
        } finally {
            DB::setDefaultConnection($default);
            app()->forgetInstance(LocalInstance::class);
            app()->forgetInstance(Outbox::class);
            app()->forgetInstance(DataKey::class);
            app()->forgetInstance(Hlc::class);
        }
    }
}
