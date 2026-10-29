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
            ->assertSessionHasErrors(['url', 'name', 'api_key_cert', 'api_key_key']);
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

    public function test_certwarden_url_is_normalised_and_download_url_shown(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['https://certs.example.com/', 'https://certs.example.com/certwarden', 'https://certs.example.com/certwarden/app',
            'https://certs.example.com/certwarden/app/certificates/'] as $input) {
            $this->assertSame('https://certs.example.com', CertificateSources::normalizeCertwardenUrl($input));
        }
        $this->assertSame('https://certwarden.example.com', CertificateSources::normalizeCertwardenUrl('https://certwarden.example.com/'));
        $this->assertSame('https://certwarden', CertificateSources::normalizeCertwardenUrl('https://certwarden'));
        $this->assertSame('https://certwarden:4055', CertificateSources::normalizeCertwardenUrl('https://certwarden:4055/certwarden/app'));

        $this->onZone('admin')->actingAs($admin)->put('/admin/certificates/router', ['type' => 'certwarden',
            'url' => 'https://certs.example.com/certwarden/app/', 'name' => 'wildcard', 'api_key_cert' => 'certapikey0123',
            'api_key_key' => 'keyapikey4567'])->assertSessionHasNoErrors();
        $this->assertSame('https://certs.example.com', CertificateSources::get('router')['url']);
        $this->onZone('admin')->actingAs($admin)->get('/admin/certificates')->assertOk()
            ->assertSee('https://certs.example.com/certwarden/api/v1/download/privatecertchains/wildcard')
            ->assertDontSee('certapikey0123');

        // Legacy stored value with path: the puller normalises too; 404 and 401 get a hint.
        CertificateSources::put('router', 'certwarden', 'https://certs.example.com/certwarden', 'wildcard', null, null);
        Http::fake(['certs.example.com/*' => Http::sequence()->push('', 404)->push('', 401)]);
        $this->onZone('admin')->actingAs($admin)->post('/admin/certificates/router/pull')
            ->assertSessionHasErrors(['pull' => __('portal.certificates.pull_failed', ['error' => 'HTTP 404 (check server URL / certificate name)'])
                .' '.__('portal.certificates.hints.not_found')]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://certs.example.com/certwarden/api/v1/download/privatecertchains/wildcard');
        $this->onZone('admin')->actingAs($admin)->get('/admin/certificates')->assertSee(__('portal.certificates.hints.not_found'));
        $this->onZone('admin')->actingAs($admin)->post('/admin/certificates/router/pull')->assertSessionHasErrors('pull');
        $this->assertStringContainsString('HTTP 401 (check API keys / order)', CertificateSources::status('router')['error']);
    }

    public function test_certwarden_keys_are_combined_in_order_and_legacy_value_is_kept(): void
    {
        $admin = User::factory()->admin()->create();
        $put = fn (array $data) => $this->onZone('admin')->actingAs($admin)->put('/admin/certificates/portal',
            ['type' => 'certwarden', 'url' => 'https://certs.example.com', 'name' => 'portal-cert'] + $data);

        // Both keys required for a new source, no dots (separator) inside a key.
        $put(['api_key_cert' => 'certapikey0123'])->assertSessionHasErrors('api_key_key');
        $put(['api_key_cert' => 'a.b', 'api_key_key' => 'c'])->assertSessionHasErrors('api_key_cert');
        $put(['api_key_cert' => 'certapikey0123', 'api_key_key' => 'keyapikey4567'])->assertSessionHasNoErrors();
        $this->assertSame(self::API_KEY, CertificateSources::apiKey('portal'));

        // Legacy combined value (saved before the two fields): changing one part keeps the other.
        CertificateSources::put('portal', 'certwarden', 'https://certs.example.com', 'portal-cert', null, 'oldcert.oldkey');
        $put([])->assertSessionHasNoErrors();
        $this->assertSame('oldcert.oldkey', CertificateSources::apiKey('portal'));
        $put(['api_key_key' => 'newkey'])->assertSessionHasNoErrors();
        $this->assertSame('oldcert.newkey', CertificateSources::apiKey('portal'));
        $put(['api_key_cert' => 'newcert'])->assertSessionHasNoErrors();
        $this->assertSame('newcert.newkey', CertificateSources::apiKey('portal'));

        // Type change from URL: a stored bearer token is not reused, both keys are required.
        CertificateSources::put('portal', 'url', 'https://pem.example.com/p.pem', null, null, 'bearer-old');
        $put(['api_key_key' => 'onlykey'])->assertSessionHasErrors('api_key_cert');
        $put([])->assertSessionHasErrors(['api_key_cert', 'api_key_key']);
        $put(['api_key_cert' => 'c1', 'api_key_key' => 'k1'])->assertSessionHasNoErrors();
        $this->assertSame('c1.k1', CertificateSources::apiKey('portal'));

        // URL source keeps its single bearer token field.
        $this->onZone('admin')->actingAs($admin)->put('/admin/certificates/router', ['type' => 'url',
            'url' => 'https://pem.example.com/router.pem', 'api_key' => 'bearer-1'])->assertSessionHasNoErrors();
        $this->assertSame('bearer-1', CertificateSources::apiKey('router'));
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
