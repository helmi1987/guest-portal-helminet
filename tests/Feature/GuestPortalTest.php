<?php

namespace Tests\Feature;

use App\Enums\ExpiryMode;
use App\Jobs\DisconnectSession;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\NasClient;
use App\Models\RadiusAccounting;
use App\Models\RadiusToken;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\GrantManager;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * M3 core paths: versioned terms, error pages, router certificate download; status page with usage, logout and the
 * target of the router's status.html (decision 2026-11-05, v0.29.0).
 */
class GuestPortalTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:01';

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

    /**
     * A logged-in device: code grant, used token, open session with two accounting rows.
     *
     * @return array{0: Grant, 1: RadiusToken, 2: SessionSummary}
     */
    private function loggedIn(): array
    {
        $grants = app(GrantManager::class);
        $grant = $grants->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin, 'site_ids' => [$this->site->id]]);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest', 'locale' => 'de']);
        [$token] = $grants->issueToken($grant, $guest, $this->site, self::MAC);
        $grants->bindDevice($grant->fresh(), self::MAC, $guest->id);
        $token->update(['used_at' => now()->subMinutes(30)]);
        $session = SessionSummary::create(['grant_id' => $grant->id, 'site_id' => $this->site->id, 'mac' => self::MAC,
            'ip' => '10.30.0.50', 'started_at' => now()->subMinutes(30)]);
        foreach ([[1_048_576, 157_286_400, 7], [524_288, 5_242_880, 3]] as $i => [$in, $out, $ago]) {
            RadiusAccounting::create(['session_summary_id' => $session->id, 'acct_session_id' => 'acct-'.$i, 'username' => $token->username,
                'bytes_in' => $in, 'bytes_out' => $out, 'last_update_at' => now()->subMinutes($ago)]);
        }

        return [$grant->fresh(), $token, $session];
    }

    private function statusUrl(Grant $grant, RadiusToken $token, string $route = 'guest.status'): string
    {
        return URL::temporarySignedRoute($route, now()->addHour(), ['grant' => $grant->id, 'token' => $token->id], absolute: false);
    }

    public function test_status_page_shows_usage_logout_and_refresh(): void
    {
        $this->travelTo(now()->setTime(14, 0));
        [$grant, $token, $session] = $this->loggedIn();
        $this->onZone('guest')->withHeader('Accept-Language', 'de')->get($this->statusUrl($grant, $token))->assertOk()
            ->assertSee(__('portal.guest.usage_title', [], 'de'))
            ->assertSee('30 Minuten')
            // Download = output of the NAS (155 MiB), upload = input (1.5 MiB); as of the newest update.
            ->assertSee('↓ 155.0 MiB · ↑ 1.5 MiB')
            ->assertSee(__('portal.guest.usage_as_of', ['time' => LocalTime::format(now()->subMinutes(3), 'H:i')], 'de'))
            ->assertSee(__('portal.guest.usage_hint', ['minutes' => 5], 'de'))
            ->assertSee('action="/status/'.$grant->id.'/'.$token->id.'/logout?expires=', false)
            ->assertSee(__('portal.guest.logout', [], 'de'))
            ->assertSee(__('portal.guest.refresh', [], 'de'));

        // No open session (stopped): no usage block, logout and refresh stay.
        $session->update(['stopped_at' => now()]);
        $this->onZone('guest')->get($this->statusUrl($grant, $token))->assertOk()
            ->assertDontSee('data-usage', false)->assertSee('gp-logout', false)->assertSee('gp-refresh', false);
    }

    public function test_router_status_page_redirects_to_the_signed_status_of_the_open_session(): void
    {
        [$grant, $token, $session] = $this->loggedIn();
        $query = ['site' => 'site-a', 'mac' => 'AA-BB-CC-00-00-01', 'ip' => '10.30.0.50', 'link-login-only' => 'https://hotspot.example.com/login'];
        $location = $this->onZone('guest')->get('/portal/status?'.http_build_query($query))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/status/'.$grant->id.'/'.$token->id.'?expires=', $location);
        $this->onZone('guest')->get(parse_url($location, PHP_URL_PATH).'?'.parse_url($location, PHP_URL_QUERY))->assertOk()->assertSee('data-usage', false);

        // Another IP than the session's, or no open session: the login page with the same query.
        $login = '/portal?'.http_build_query($query);
        $this->onZone('guest')->get('/portal/status?'.http_build_query(['ip' => '10.30.0.99'] + $query))
            ->assertRedirect('/portal?'.http_build_query(['ip' => '10.30.0.99'] + $query));
        $session->update(['stopped_at' => now()]);
        $this->onZone('guest')->get('/portal/status?'.http_build_query($query))->assertRedirect($login);
        $this->onZone('guest')->get('/portal/status?site=nope&mac=AA-BB-CC-00-00-01')->assertOk()
            ->assertSee(__('portal.guest.error_pages.no_hotspot.title'));
    }

    public function test_logout_ends_the_session_not_the_grant(): void
    {
        Bus::fake([DisconnectSession::class]);
        [$grant, $token, $session] = $this->loggedIn();
        // Sessions of other devices or already stopped ones stay untouched.
        $other = SessionSummary::create(['grant_id' => $grant->id, 'site_id' => $this->site->id, 'mac' => 'AA:BB:CC:00:00:02',
            'started_at' => now()->subHour()]);
        $old = SessionSummary::create(['grant_id' => $grant->id, 'site_id' => $this->site->id, 'mac' => self::MAC,
            'started_at' => now()->subHours(2), 'stopped_at' => now()->subHour()]);
        $logout = $this->statusUrl($grant, $token, 'guest.logout');

        // Signature: tampered or unsigned is 403 (CSRF: the form carries the token like every guest POST).
        $this->onZone('guest')->post(str_replace('/logout?', '/logout?x=1&', $logout))->assertForbidden();
        $this->onZone('guest')->post('/status/'.$grant->id.'/'.$token->id.'/logout')->assertForbidden();
        $this->onZone('guest')->post(str_replace('/'.$token->id.'/logout', '/'.($token->id + 1).'/logout', $logout))->assertForbidden();
        Bus::assertNothingDispatched();
        $this->onZone('guest')->get($this->statusUrl($grant, $token))->assertSee('name="_token"', false);

        $this->onZone('guest')->withHeader('Accept-Language', 'de')->post($logout)->assertOk()
            ->assertSee(__('portal.guest.logged_out_title', [], 'de'))->assertSee(__('portal.guest.logged_out', [], 'de'))
            ->assertSee('href="/portal?site=site-a&amp;mac=AA%3ABB%3ACC%3A00%3A00%3A01&amp;ip=10.30.0.50&amp;link-login-only=https%3A%2F%2Fhotspot.example.com%2Flogin"', false);
        Bus::assertDispatchedTimes(DisconnectSession::class, 1);
        Bus::assertDispatched(DisconnectSession::class, fn ($job) => $job->sessionSummaryId === $session->id);
        $this->assertNotNull($session->fresh()->logged_out_at);
        $this->assertNull($other->fresh()->logged_out_at);
        $this->assertNull($old->fresh()->logged_out_at);
        $this->assertTrue($grant->fresh()->isUsable());
        $this->assertSame(self::MAC, $grant->fresh()->activeDevice()?->mac);

        // The status page shows the logged-out page again; status.html leads to the login page.
        $this->onZone('guest')->get($this->statusUrl($grant, $token))->assertOk()->assertSee('data-logged-out', false);
        $this->onZone('guest')->get('/portal/status?site=site-a&mac=AA:BB:CC:00:00:01')->assertRedirect('/portal?site=site-a&mac=AA%3ABB%3ACC%3A00%3A00%3A01');

        // Back on the login page (router redirect): the logged-out notice; the same code works again on this device.
        $session->update(['stopped_at' => now()]);
        $this->enterPortal()->assertSee('gp-access-ended', false)->assertSee(__('portal.guest.access_ended.logged_out', [], 'de'));
        $this->submit($grant->code, null)->assertOk()->assertViewHas('fields');
    }

    public function test_router_side_logout_shows_the_logged_out_notice(): void
    {
        [, , $session] = $this->loggedIn();
        $session->update(['stopped_at' => now()]);
        $session->accounting()->update(['terminate_cause' => 'Lost-Carrier']);
        $this->enterPortal()->assertDontSee(__('portal.guest.access_ended.logged_out', [], 'de'));

        $session->accounting()->first()->update(['terminate_cause' => 'User-Request']);
        $this->enterPortal()->assertSee(__('portal.guest.access_ended.logged_out', [], 'de'));

        // Logged out before disconnected when both are set.
        $session->update(['kicked_at' => now()]);
        $this->enterPortal()->assertSee(__('portal.guest.access_ended.logged_out', [], 'de'))
            ->assertDontSee(__('portal.guest.access_ended.disconnected', [], 'de'));
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
