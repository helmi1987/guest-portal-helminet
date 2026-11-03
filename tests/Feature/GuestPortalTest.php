<?php

namespace Tests\Feature;

use App\Enums\ExpiryMode;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\GrantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M3 core paths: versioned terms, error pages, router certificate download.
 */
class GuestPortalTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => 'https://portal.example.com']]);
    }

    public function test_admin_publishes_terms_versions_and_guest_acceptance_is_recorded(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get('/admin/terms')->assertOk();
        $this->onZone('admin')->actingAs($admin)->post('/admin/terms/de', ['body' => "Regel eins.\n\nRegel zwei."])->assertRedirect();
        $this->onZone('admin')->actingAs($admin)->post('/admin/terms/de', ['body' => "Regel eins.\r\n\r\nRegel zwei."]);
        $v1 = TermsVersion::sole();
        $this->assertSame(1, $v1->number);

        $this->enterPortal()->assertOk()->assertSee('Regel zwei.')->assertSee('Version 1');
        $code = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin])->code;

        // A new version published meanwhile must be accepted first.
        $this->onZone('admin')->actingAs($admin)->post('/admin/terms/de', ['body' => 'Neue Regel.']);
        $this->submit($code, $v1->id)->assertSessionHasErrors('terms');
        $this->onZone('guest')->post('/portal/code', ['first_name' => 'A', 'last_name' => '', 'email' => 'a@example.com', 'terms' => '1', 'code' => $code])
            ->assertSessionHasErrors('last_name');

        $v2 = TermsVersion::latestFor('de');
        $this->submit($code, $v2->id)->assertOk()->assertViewHas('fields');
        $acceptance = TermsAcceptance::sole();
        $this->assertSame($v2->id, $acceptance->terms_version_id);
        $this->assertNotNull($acceptance->grant_id);
        $this->assertNotNull($acceptance->accepted_at);
    }

    public function test_misconfigured_hotspot_host_and_missing_redirect_show_error_pages(): void
    {
        $this->onZone('guest')->get('/portal')->assertOk()->assertSee(__('portal.guest.error_pages.no_hotspot.title'));
        $this->enterPortal('https://other.example.net/login')->assertOk()
            ->assertSee(__('portal.guest.error_pages.misconfigured.title'));
    }

    public function test_router_certificate_download_requires_router_token(): void
    {
        $dir = sys_get_temp_dir().'/portal-cert-'.getmypid();
        @mkdir($dir);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => '*.guest.example.com'], $key), null, $key, 30);
        openssl_x509_export_to_file($cert, "$dir/router.crt");
        openssl_pkey_export_to_file($key, "$dir/router.key");
        config(['portal.router_cert.cert' => "$dir/router.crt", 'portal.router_cert.key' => "$dir/router.key"]);

        $nas = $this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
        $nas = NasClient::find($nas->id);
        $script = $this->site->driver()->configScript($this->site, $nas);
        $this->assertStringContainsString("https://portal.example.com/router-cert/{$nas->id}", $script);
        $this->assertStringContainsString('X-Portal-Token: '.$nas->cert_token, $script);
        $this->assertStringContainsString('port=3799', $script);
        $this->assertStringContainsString(':local gpSrc "10.0.0.1"', $script);
        $this->assertStringContainsString('remove [find where name="guest-portal-cert" && ((comment="guest-portal:cert:script" || comment="guest-portal" || comment~"^guest-portal: ") || !comment)]', $script);

        $url = "/router-cert/{$nas->id}";
        $this->onZone('guest')->get("$url/router.key")->assertForbidden();
        $this->onZone('guest')->withHeader('X-Portal-Token', 'wrong')->get("$url/router.key")->assertForbidden();
        $this->onZone('admin')->withHeader('X-Portal-Token', $nas->cert_token)->get("$url/router.key")->assertNotFound();

        // Correct token, but not from the router's NAS IP.
        $this->onZone('guest')->withHeader('X-Portal-Token', $nas->cert_token)->get("$url/router.key")->assertForbidden();

        $ok = fn (string $file) => $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Portal-Token', $nas->cert_token)->get("$url/$file")->assertCookieMissing('portal_guest_session')->assertCookieMissing('XSRF-TOKEN');
        $this->assertSame(openssl_x509_fingerprint($cert, 'sha256'), $ok('fingerprint')->assertOk()->getContent());
        $this->assertStringContainsString('BEGIN CERTIFICATE', $ok('router.crt')->assertOk()->getContent());
        $this->assertStringContainsString('PRIVATE KEY', $ok('router.key')->assertOk()->getContent());
    }

    private function enterPortal(string $loginUrl = 'https://hotspot.example.com/login')
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'de')->get('/portal?'.http_build_query([
            'site' => 'site-a', 'mac' => 'AA:BB:CC:00:00:01', 'link-login-only' => $loginUrl,
        ]));
    }

    private function submit(string $code, ?string $termsVersion)
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'de')->post('/portal/code', [
            'first_name' => 'Test', 'last_name' => 'Guest', 'email' => 'guest@example.com', 'terms' => '1', 'code' => $code,
            'terms_version' => $termsVersion,
        ]);
    }
}
