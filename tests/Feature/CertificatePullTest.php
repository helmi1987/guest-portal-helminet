<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LocalSetting;
use App\Models\Site;
use App\Models\User;
use App\Tls\CertificateSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M6: certificate sources (CertWarden, URL), validation and atomic install.
 */
class CertificatePullTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'certapikey0123.keyapikey4567';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/portal-cert-test-'.getmypid().'-'.bin2hex(random_bytes(3));
        File::ensureDirectoryExists($this->tmp);
        config([
            'portal.portal_cert' => ['cert' => $this->tmp.'/portal.crt', 'key' => $this->tmp.'/portal.key'],
            'portal.router_cert.cert' => $this->tmp.'/router.crt',
            'portal.router_cert.key' => $this->tmp.'/router.key',
            'portal.hostname' => 'portal.example.com',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_certwarden_pull_installs_validated_certificate(): void
    {
        $admin = User::factory()->admin()->create();
        [$cert, $key] = $this->makeCert('portal.example.com');
        [$chain] = $this->makeCert('Test CA');
        Http::fake(['certs.example.com/*' => Http::response($key.$cert.$chain)]);

        $this->onZone('admin')->actingAs($admin)->put('/admin/certificates/portal', ['type' => 'certwarden',
            'url' => 'https://certs.example.com/', 'name' => 'portal-cert', 'api_key' => self::API_KEY])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('certapikey0123', json_encode(LocalSetting::all()->toArray()));
        $this->assertSame(self::API_KEY, CertificateSources::apiKey('portal'));

        $this->onZone('admin')->actingAs($admin)->post('/admin/certificates/portal/pull')
            ->assertSessionHasNoErrors()->assertSessionHas('status', __('portal.certificates.pull_updated'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://certs.example.com/certwarden/api/v1/download/privatecertchains/portal-cert'
            && $r->hasHeader('X-API-Key', self::API_KEY));
        $this->assertSame(trim($cert)."\n".trim($chain)."\n", file_get_contents($this->tmp.'/portal.crt'));
        $this->assertSame(trim($key)."\n", file_get_contents($this->tmp.'/portal.key'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->tmp.'/portal.key')), -4));
        $this->assertSame(['portal.crt', 'portal.key'], array_values(array_diff(scandir($this->tmp), ['.', '..'])));

        // Same content again: nothing rewritten.
        $this->onZone('admin')->actingAs($admin)->post('/admin/certificates/portal/pull')
            ->assertSessionHas('status', __('portal.certificates.pull_unchanged'));
        $this->assertSame(1, AuditLog::where('action', 'certificate.updated')->count());

        $this->onZone('admin')->actingAs($admin)->get('/admin/certificates')->assertOk()
            ->assertSee('CN=portal.example.com')->assertDontSee('certapikey0123')->assertDontSee('keyapikey4567');
        $this->assertStringNotContainsString('apikey', AuditLog::query()->pluck('data')->toJson());
    }

    public function test_invalid_bundles_and_http_errors_keep_existing_files(): void
    {
        $admin = User::factory()->admin()->create();
        file_put_contents($this->tmp.'/router.crt', 'OLD');
        CertificateSources::put('router', 'url', 'https://pem.example.com/router.pem', null, null, 'bearer-secret-1');
        [$cert] = $this->makeCert('*.guest.example.com');
        [, $otherKey] = $this->makeCert('other');
        [$expired, $expiredKey] = $this->makeCert('old.example.com', 1);
        $this->travel(2)->days(); // negative validity days are rejected by newer OpenSSL bindings
        Http::fake(['pem.example.com/*' => Http::sequence()
            ->push($otherKey.$cert)
            ->push($expiredKey.$expired)
            ->push('denied bearer-secret-1', 401)]);

        foreach (['does not match', 'expired', 'HTTP 401'] as $expected) {
            $this->onZone('admin')->actingAs($admin)->post('/admin/certificates/router/pull')->assertSessionHasErrors('pull');
            $this->assertStringContainsString($expected, CertificateSources::status('router')['error']);
        }
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer bearer-secret-1'));
        $this->assertSame('OLD', file_get_contents($this->tmp.'/router.crt'));
        $this->assertFileDoesNotExist($this->tmp.'/router.key');
        $this->assertStringNotContainsString('bearer-secret-1', json_encode(CertificateSources::status('router')));
    }

    public function test_scheduled_command_pulls_configured_targets_and_settings_are_validated(): void
    {
        $admin = User::factory()->admin()->create();
        [$cert, $key] = $this->makeCert('*.guest.example.com');
        Http::fake(['pem.example.com/*' => Http::response($cert.$key)]);
        CertificateSources::put('router', 'url', 'https://pem.example.com/router.pem', null, null, null);

        $this->assertSame(0, Artisan::call('portal:cert-pull'));
        $this->assertSame(trim($cert)."\n", file_get_contents($this->tmp.'/router.crt'));
        $this->assertFileDoesNotExist($this->tmp.'/portal.crt');
        Http::assertSentCount(1);

        $this->onZone('admin')->actingAs($admin)->put('/admin/certificates/portal', ['type' => 'certwarden',
            'url' => 'http://certs.example.com', 'name' => 'a/b', 'ca_pem' => 'nonsense'])
            ->assertSessionHasErrors(['url', 'name', 'api_key']);
        $this->onZone('admin')->actingAs($admin)->put('/admin/certificates/portal', ['type' => 'url',
            'url' => 'https://certs.example.com', 'ca_pem' => 'nonsense'])->assertSessionHasErrors(['ca_pem']);
        $this->onZone('admin')->actingAs(User::factory()->create())->get('/admin/certificates')->assertForbidden();
    }

    public function test_redirects_wrong_portal_names_and_router_warnings(): void
    {
        $admin = User::factory()->admin()->create();
        [$other, $otherKey] = $this->makeCert('other.example.com');
        [$wild, $wildKey] = $this->makeCert('*.guest.example.com');
        CertificateSources::put('portal', 'url', 'https://pem.example.com/portal.pem', null, null, null);
        CertificateSources::put('router', 'url', 'https://pem.example.com/router.pem', null, null, null);
        Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot-a.guest.example.com', 'hotspot.other.example.com']]]);
        Http::fake([
            'pem.example.com/portal.pem' => Http::sequence()
                ->push('', 302, ['Location' => 'https://elsewhere.example.com/x'])
                ->push($otherKey.$other),
            'pem.example.com/router.pem' => Http::response($wildKey.$wild),
        ]);

        foreach (['HTTP 302 (redirects are not followed)', 'does not cover the portal host name portal.example.com'] as $expected) {
            $this->onZone('admin')->actingAs($admin)->post('/admin/certificates/portal/pull')->assertSessionHasErrors('pull');
            $this->assertStringContainsString($expected, CertificateSources::status('portal')['error']);
        }
        $this->assertFileDoesNotExist($this->tmp.'/portal.crt');

        // Router: installed, uncovered hotspot host names only warned about.
        $this->onZone('admin')->actingAs($admin)->post('/admin/certificates/router/pull')->assertSessionHasNoErrors();
        $this->assertFileExists($this->tmp.'/router.crt');
        $this->assertSame('not covered: hotspot.other.example.com', CertificateSources::status('router')['warning']);
        $this->onZone('admin')->actingAs($admin)->get('/admin/certificates')->assertSee('hotspot.other.example.com');
    }

    public function test_source_url_rejects_userinfo_and_masks_query(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->put('/admin/certificates/router', ['type' => 'url',
            'url' => 'https://user:pass@pem.example.com/router.pem'])->assertSessionHasErrors('url');

        $url = 'https://pem.example.com/router.pem?token=querysecret9';
        $this->onZone('admin')->actingAs($admin)->put('/admin/certificates/router', ['type' => 'url', 'url' => $url])
            ->assertSessionHasNoErrors();
        $this->onZone('admin')->actingAs($admin)->get('/admin/certificates')->assertOk()
            ->assertSee('https://pem.example.com/router.pem?***')->assertDontSee('querysecret9');
        // Submitting the masked URL keeps the stored one.
        $this->onZone('admin')->actingAs($admin)->put('/admin/certificates/router', ['type' => 'url',
            'url' => 'https://pem.example.com/router.pem?***'])->assertSessionHasNoErrors();
        $this->assertSame($url, CertificateSources::get('router')['url']);
        $this->assertStringNotContainsString('querysecret9', AuditLog::query()->pluck('data')->toJson());

        Http::fake(['*' => Http::response('nothing querysecret9', 500)]);
        $this->onZone('admin')->actingAs($admin)->post('/admin/certificates/router/pull')->assertSessionHasErrors('pull');
        $this->assertStringNotContainsString('querysecret9', json_encode(CertificateSources::status('router')));
    }

    /** @return array{0: string, 1: string} self-signed certificate PEM and key PEM */
    private function makeCert(string $cn, int $days = 30): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']), $certPem);
        openssl_pkey_export($key, $keyPem);

        return [$certPem, $keyPem];
    }
}
