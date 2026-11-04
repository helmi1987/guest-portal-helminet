<?php

namespace Tests\Feature;

use App\Enums\ExpiryMode;
use App\Federation\FederatedSecrets;
use App\Federation\Federation;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\RadiusToken;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Models\TermsVersion;
use App\Models\User;
use App\Nas\AuthorizesViaController;
use App\Nas\HotspotContext;
use App\Nas\NasDrivers;
use App\Nas\Unifi\LegacyClient;
use App\Nas\Unifi\UnifiClient;
use App\Nas\Unifi\UnifiException;
use App\Nas\Unifi\UnifiSettings;
use App\Nas\UnifiDriver;
use App\Services\GrantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Experimental UniFi driver (decision 2026-11-04, v0.27.0): both controller API modes against a faked
 * controller (shapes after the OpenAPI files and the legacy client), the UniFi redirect route, the guest
 * flow without RADIUS, enforcement, admin settings and the check button.
 */
class UnifiDriverTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:01';

    private const AP = 'AA:BB:CC:00:00:99';

    private const URL = 'https://unifi.example.com';

    private const API_KEY = 'test-api-key-0123456789';

    private const PASSWORD = 'Local pass 1!';

    private Site $site;

    /** @var array<string, array> connected clients of the fake controller by lower-case MAC */
    private array $clients = [];

    /** @var list<int> statuses answered before the normal answer (e.g. 429) */
    private array $preface = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-u', 'name' => 'Site U', 'driver' => 'unifi',
            'settings' => ['portal_url' => 'https://portal.example.com']]);
        TermsVersion::publish('en', 'Be nice.');
        $this->connect(self::MAC);
    }

    // --- Official API -------------------------------------------------------------------------------

    public function test_official_mode_authorizes_a_connected_guest_with_the_remaining_minutes(): void
    {
        $this->configure();
        $this->fakeOfficial();
        $grant = $this->grant(5400 + 30);

        $result = $this->driver()->authorizeClient($this->site, $this->context(), $grant, 5430);

        $this->assertTrue($result->granted);
        $this->assertSame('client-1', $result->providerId);
        $this->assertSame('2026-11-04T12:00:00+00:00', $result->expiresAt->toIso8601String());
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::URL.'/proxy/network/integration/v1/sites/site-uuid/clients/client-1/actions'
            && $r['action'] === 'AUTHORIZE_GUEST_ACCESS' && $r['timeLimitMinutes'] === 91
            && $r->header('X-API-KEY') === [self::API_KEY]);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), "filter=macAddress.eq('aa:bb:cc:00:00:01')"));
    }

    public function test_official_mode_refuses_unknown_non_guest_and_foreign_ap_clients(): void
    {
        $this->configure();
        $this->fakeOfficial();
        $grant = $this->grant();

        $this->assertSame('not_connected', $this->driver()->authorizeClient($this->site, $this->context('AA:BB:CC:00:00:05'), $grant, 600)->error);
        $this->assertSame('wrong_ap', $this->driver()->authorizeClient($this->site, $this->context(self::MAC, 'AA:BB:CC:00:00:98'), $grant, 600)->error);
        $this->clients[strtolower(self::MAC)]['guest'] = false;
        $this->assertSame('not_guest', $this->driver()->authorizeClient($this->site, $this->context(), $grant, 600)->error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/actions'));
    }

    public function test_official_mode_unauthorizes_by_client_id_and_skips_offline_clients(): void
    {
        $this->configure();
        $this->fakeOfficial();

        $this->assertTrue($this->driver()->deauthorizeClient($this->site, self::MAC));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/clients/client-1/actions') && $r['action'] === 'UNAUTHORIZE_GUEST_ACCESS');

        // Not connected: nothing to address (the API lists connected clients only).
        $this->assertTrue($this->driver()->deauthorizeClient($this->site, 'AA:BB:CC:00:00:05'));
    }

    public function test_site_lookup_by_internal_reference_and_unknown_site(): void
    {
        $this->configure(['unifi_site' => 'branch']);
        $this->fakeOfficial();

        $result = $this->driver()->authorizeClient($this->site->fresh(), $this->context(), $this->grant(), 600);
        $this->assertFalse($result->granted);
        $this->assertSame('site_not_found', $result->error);
    }

    // --- Legacy API ---------------------------------------------------------------------------------

    public function test_legacy_mode_logs_in_with_cookie_and_csrf_and_authorizes_by_mac(): void
    {
        $this->configure(['mode' => UnifiSettings::MODE_LEGACY, 'username' => 'portal']);
        $this->fakeLegacy();
        $grant = $this->grant(3600);

        $result = $this->driver()->authorizeClient($this->site, $this->context(), $grant, 3600);

        $this->assertTrue($result->granted, (string) $result->rawStatus);
        $this->assertSame(1_800_000_000, $result->expiresAt->getTimestamp());
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/api/login' && $r['username'] === 'portal' && $r['password'] === self::PASSWORD);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/api/s/default/cmd/stamgr'
            && $r['cmd'] === 'authorize-guest' && $r['mac'] === 'aa:bb:cc:00:00:01' && $r['minutes'] === 60
            && $r['ap_mac'] === 'aa:bb:cc:00:00:99'
            && str_contains($r->header('Cookie')[0], 'unifises=session-1') && $r->header('X-CSRF-Token') === ['csrf-1']);
        // One login per request or command run.
        $this->driver()->deauthorizeClient($this->site, self::MAC);
        Http::assertSentCount(5); // login, sites, stat/sta, authorize, unauthorize
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/cmd/stamgr') && $r['cmd'] === 'unauthorize-guest');
    }

    public function test_legacy_mode_detects_unifi_os_and_uses_the_proxy_prefix(): void
    {
        $this->configure(['mode' => UnifiSettings::MODE_LEGACY, 'username' => 'portal']);
        $this->fakeLegacy(unifiOs: true);

        $client = $this->driver()->client($this->site);
        $this->assertInstanceOf(LegacyClient::class, $client);
        $this->assertNotNull($client->findClient(self::MAC));
        $this->assertSame('unifi_os', $client->variant());
        Http::assertSent(fn (Request $r) => $r->url() === self::URL.'/proxy/network/api/s/default/stat/sta/aa:bb:cc:00:00:01'
            && $r->header('X-CSRF-Token') === ['csrf-os'] && str_contains($r->header('Cookie')[0], 'TOKEN='));
    }

    public function test_legacy_login_refused_is_an_auth_error_without_the_password(): void
    {
        $this->configure(['mode' => UnifiSettings::MODE_LEGACY, 'username' => 'portal']);
        Http::fake(['*' => Http::response(['meta' => ['rc' => 'error', 'msg' => 'api.err.Invalid'], 'data' => []], 400)]);

        $check = $this->driver()->checkController($this->site);
        $this->assertSame('auth', $check['status']);
        $this->assertStringNotContainsString(self::PASSWORD, $check['message']);
    }

    // --- Shared client behaviour ---------------------------------------------------------------------

    public function test_rate_limit_is_retried_once_after_two_seconds(): void
    {
        Sleep::fake();
        $this->configure();
        $this->preface = [429];
        $this->fakeOfficial();
        $this->assertTrue($this->driver()->authorizeClient($this->site, $this->context(), $this->grant(), 600)->granted);
        Sleep::assertSleptTimes(1);

        $this->preface = [429, 429];
        $result = app(UnifiDriver::class)->authorizeClient($this->site, $this->context(), $this->grant(), 600);
        $this->assertSame('rate_limited', $result->error);
    }

    public function test_errors_never_contain_the_credentials(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['statusCode' => 401, 'code' => 'api.authentication.invalid',
            'message' => 'Invalid key '.self::API_KEY], 401)]);

        try {
            UnifiClient::make(UnifiSettings::config($this->site))->sites();
            $this->fail('No exception');
        } catch (UnifiException $e) {
            $this->assertSame('auth', $e->reason);
            $this->assertSame(401, $e->status);
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
        $this->assertStringNotContainsString(self::API_KEY, print_r(UnifiSettings::config($this->site), true));
    }

    public function test_tls_verification_flag_and_pin(): void
    {
        $this->configure();
        $this->assertSame(['verify' => true], UnifiClient::make(UnifiSettings::config($this->site))->tlsOptions());
        $this->configure(['verify_tls' => false]);
        $this->assertSame(['verify' => false], UnifiClient::make(UnifiSettings::config($this->site->fresh()))->tlsOptions());
        $pin = 'sha256//'.str_repeat('A', 43).'=';
        $this->configure(['verify_tls' => true, 'tls_pin' => $pin]);
        $this->assertSame(['verify' => false, 'pin' => $pin], UnifiClient::make(UnifiSettings::config($this->site->fresh()))->tlsOptions());
    }

    // --- Redirect route -----------------------------------------------------------------------------

    public function test_redirect_route_resolves_the_site_and_keeps_the_vendor_fields(): void
    {
        $this->configure();
        $redirect = $this->guest()->get('/guest/s/default/?'.http_build_query(['ap' => strtolower(self::AP), 'id' => strtolower(self::MAC),
            't' => '1762250000', 'url' => 'http://www.example.org/news', 'ssid' => 'Guest WiFi']))->assertRedirect();
        $location = $redirect->headers->get('Location');
        $target = parse_url($location, PHP_URL_PATH).'?'.parse_url($location, PHP_URL_QUERY);
        $this->assertStringStartsWith('/portal?site=site-u&unifi_site=default&', $target);

        $this->guest()->get($target)->assertOk()->assertSee(__('portal.guest.intros.code'));
        $context = HotspotContext::fromArray(session('portal')['context']);
        $this->assertSame([self::MAC, self::AP, 'Guest WiFi', 'default', 1762250000, 'http://www.example.org/news', null],
            [$context->mac, $context->ap, $context->ssid, $context->unifiSite, $context->timestamp, $context->origUrl, $context->loginUrl]);
        $this->assertTrue($context->isComplete());

        // Without the trailing slash as well; unknown UniFi sites get the "no hotspot" page.
        $this->guest()->get('/guest/s/default?id='.self::MAC)->assertRedirect();
        $this->guest()->get('/guest/s/other/?id='.self::MAC)->assertOk()->assertSee(__('portal.guest.error_pages.no_hotspot.title'));
        // A forged UniFi site in the portal URL makes the context incomplete.
        $this->guest()->get('/portal?site=site-u&unifi_site=other&id='.self::MAC)->assertOk()
            ->assertSee(__('portal.guest.error_pages.no_hotspot.title'));
        // MikroTik contexts keep their shape.
        $this->assertSame(['mac' => 'AA', 'ip' => null, 'login_url' => 'x', 'orig_url' => null],
            HotspotContext::fromArray(['mac' => 'AA', 'login_url' => 'x'])->toArray());
    }

    public function test_hostile_redirect_values_are_dropped(): void
    {
        $this->configure();
        $context = $this->driver()->parseRedirect(\Illuminate\Http\Request::create('/portal', 'GET', ['site' => 'site-u',
            'unifi_site' => 'default', 'id' => 'not-a-mac', 'ap' => '<script>', 'url' => 'javascript:alert(1)',
            'ssid' => "bad\x00ssid", 't' => '12ab']), $this->site);
        $this->assertSame([null, null, null, null, null], [$context->mac, $context->ap, $context->origUrl, $context->ssid, $context->timestamp]);
        $this->assertFalse($context->isComplete());
    }

    // --- Guest flow ---------------------------------------------------------------------------------

    public function test_code_login_authorizes_through_the_controller_without_radius(): void
    {
        $this->configure();
        $this->fakeOfficial();
        $grant = $this->grant(7200);

        $response = $this->login($grant->code)->assertRedirect();
        $status = $response->headers->get('Location');
        $this->assertStringContainsString('/status/'.$grant->id.'/', $status);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/clients/client-1/actions') && $r['timeLimitMinutes'] === 120);
        $grant->refresh();
        $this->assertSame(self::MAC, $grant->activeDevice()->mac);
        $token = RadiusToken::query()->where('grant_id', $grant->id)->sole();
        $this->assertNotNull($token->used_at);
        $session = SessionSummary::query()->where('grant_id', $grant->id)->sole();
        $this->assertNull($session->stopped_at);
        $this->assertSame('10.0.50.10', $session->ip);
        $this->assertSame('controller:'.$token->id, $session->accounting()->sole()->acct_session_id);

        // Status page: no RADIUS wait, the controller's state and the original URL as a link.
        $this->clients[strtolower(self::MAC)]['authorized'] = true;
        $this->guest()->get($status)->assertOk()->assertSee(__('portal.guest.online'))
            ->assertSee(__('portal.guest.controller_connected'))->assertSee('http://www.example.org/news')
            ->assertDontSee(__('portal.guest.waiting'));

        // Guest list: UniFi session without RADIUS accounting.
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get('/admin/guests')->assertOk()->assertSee(__('portal.guests.controller_session'));
    }

    public function test_failed_authorization_shows_the_error_page_and_binds_nothing(): void
    {
        $this->configure();
        $this->fakeOfficial();
        unset($this->clients[strtolower(self::MAC)]);
        $grant = $this->grant();

        $this->login($grant->code)->assertStatus(502)->assertSee(__('portal.guest.error_pages.controller_failed.title'));
        $this->assertNull($grant->fresh()->activeDevice());
        $this->assertSame(0, SessionSummary::query()->count());
    }

    public function test_revoke_extend_and_enforcement_go_through_the_controller(): void
    {
        $this->configure();
        $this->fakeOfficial();
        $grant = $this->grant(3600);
        $this->login($grant->code)->assertRedirect();
        $session = SessionSummary::sole();

        // Poller: usage and IP from the controller (rx = download, tx = upload).
        $this->clients[strtolower(self::MAC)] = array_merge($this->clients[strtolower(self::MAC)], ['rx' => 5000, 'tx' => 700, 'ip' => '10.0.50.11']);
        Artisan::call('portal:poll-controllers');
        $acct = $session->accounting()->sole();
        $this->assertSame([700, 5000], [$acct->bytes_in, $acct->bytes_out]);
        $this->assertSame('10.0.50.11', $session->fresh()->ip);

        // Extension: authorized again with the new remaining time.
        app(GrantManager::class)->extend($grant->fresh(), 3600);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/actions') && $r['action'] === 'AUTHORIZE_GUEST_ACCESS'
            && $r['timeLimitMinutes'] === 120);

        // Not ended: enforcement leaves it open even without fresh accounting (no stale check).
        $this->travel(30)->minutes();
        Artisan::call('portal:enforce-grants');
        $this->assertNull($session->fresh()->stopped_at);

        // Revoke: unauthorized on the controller, session closed.
        app(GrantManager::class)->revoke($grant->fresh());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/actions') && $r['action'] === 'UNAUTHORIZE_GUEST_ACCESS');
        $this->assertNotNull($session->fresh()->stopped_at);
        $this->assertSame('Controller-Unauthorized', $session->accounting()->sole()->terminate_cause);
    }

    public function test_enforcement_unauthorizes_sessions_of_expired_grants(): void
    {
        $this->configure();
        $this->fakeOfficial();
        $grant = $this->grant(600);
        $this->login($grant->code)->assertRedirect();

        $this->travel(11)->minutes();
        Artisan::call('portal:enforce-grants');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/actions') && $r['action'] === 'UNAUTHORIZE_GUEST_ACCESS');
        $this->assertNotNull(SessionSummary::sole()->stopped_at);
    }

    // --- Admin --------------------------------------------------------------------------------------

    public function test_admin_saves_the_controller_with_encrypted_masked_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk()
            ->assertSee(__('portal.controller.title'))->assertSee(__('portal.controller.experimental'))
            ->assertSee(__('portal.controller.limits'))->assertDontSee(__('portal.sites.routers'));

        $this->saveController($admin, ['api_key' => self::API_KEY])->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sites.edit', $this->site).'#controller');
        $site = $this->site->fresh();
        $raw = DB::table('sites')->where('id', $site->id)->value('settings');
        $this->assertStringNotContainsString(self::API_KEY, $raw);
        $this->assertStringStartsWith('fdk1:', $site->setting('controller.api_key'));
        $this->assertSame(self::API_KEY, UnifiSettings::secret($site, 'api_key'));
        $this->assertTrue(UnifiSettings::isConfigured($site));
        $audit = AuditLog::query()->where('action', 'site.controller_updated')->sole();
        $this->assertTrue($audit->data['credential_changed']);
        $this->assertStringNotContainsString(self::API_KEY, json_encode($audit->data));

        $page = $this->admin($admin)->get("/admin/sites/{$site->id}/edit")->assertOk()
            ->assertSee(__('portal.controller.secret_stored'))->getContent();
        $this->assertStringNotContainsString(self::API_KEY, $page);
        $this->assertStringNotContainsString((string) $site->setting('controller.api_key'), $page);

        // Empty field keeps the key; the general site form keeps the controller.
        $this->saveController($admin, ['unifi_site' => 'branch'])->assertSessionHasNoErrors();
        $this->assertSame(self::API_KEY, UnifiSettings::secret($this->site->fresh(), 'api_key'));
        $this->admin($admin)->put("/admin/sites/{$site->id}", ['name' => 'Site U', 'slug' => 'site-u', 'driver' => 'unifi',
            'portal_url' => 'https://portal.example.com'])->assertSessionHasNoErrors();
        $this->assertSame('branch', UnifiSettings::forSite($this->site->fresh())['unifi_site']);

        // Validation: HTTPS only, no path, pin format, credentials per mode.
        $this->saveController($admin, ['url' => 'http://unifi.example.com'])->assertSessionHasErrors('controller.url');
        $this->saveController($admin, ['url' => 'https://unifi.example.com/manage'])->assertSessionHasErrors('controller.url');
        $this->saveController($admin, ['tls_pin' => 'sha1//abc'])->assertSessionHasErrors('controller.tls_pin');
        $this->saveController($admin, ['unifi_site' => 'a/b'])->assertSessionHasErrors('controller.unifi_site');
        $this->saveController($admin, ['api_key_clear' => '1'])->assertSessionHasErrors('controller.api_key');
        $this->saveController($admin, ['mode' => 'legacy'])->assertSessionHasErrors(['controller.username', 'controller.password']);
        $this->saveController($admin, ['mode' => 'legacy', 'username' => 'portal', 'password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->assertSame(self::PASSWORD, UnifiSettings::secret($this->site->fresh(), 'password'));
        $this->saveController($admin, ['verify_tls' => '0'])->assertSessionHas('warning', __('portal.controller.verify_off_warning'));

        // Managers have no access; MikroTik sites have no controller section.
        $manager = User::factory()->create();
        $this->admin($manager)->put("/admin/sites/{$site->id}/controller", ['controller' => self::input()])->assertForbidden();
        $mikrotik = Site::create(['slug' => 'site-m', 'name' => 'Site M', 'driver' => 'mikrotik_ros7', 'settings' => []]);
        $this->admin($admin)->put("/admin/sites/{$mikrotik->id}/controller", ['controller' => self::input()])->assertNotFound();
        $this->admin($admin)->get("/admin/sites/{$mikrotik->id}/edit")->assertOk()->assertDontSee(__('portal.controller.title'));
    }

    public function test_check_button_reports_version_site_and_clients(): void
    {
        $admin = User::factory()->admin()->create();
        $this->admin($admin)->post("/admin/sites/{$this->site->id}/controller/check")
            ->assertSessionHas('controller_check.status', 'not_configured');

        $this->configure();
        $this->fakeOfficial();
        $this->admin($admin)->post("/admin/sites/{$this->site->id}/controller/check")
            ->assertRedirect(route('admin.sites.edit', $this->site).'#controller')
            ->assertSessionHas('controller_check.message', __('portal.controller.check.ok', ['version' => '10.0.162', 'site' => 'default', 'clients' => 1]));
        $this->assertSame('ok', AuditLog::query()->where('action', 'site.controller_checked')->latest('id')->first()->data['status']);
    }

    public function test_setup_page_shows_the_checklist_instead_of_router_blocks(): void
    {
        $this->configure(['unifi_site' => 'branch']);
        $this->site->update(['settings' => array_merge($this->site->fresh()->settings, ['portal_address' => '10.0.0.10'])]);
        $admin = User::factory()->admin()->create();

        $this->admin($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee(__('portal.controller.checklist_title'))->assertSee('External Portal Server')
            ->assertSee('portal.example.com/guest/s/branch/', false)->assertSee('10.0.0.10')
            ->assertDontSee(__('portal.router_api.state_title'));
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/wizard")->assertRedirect(route('admin.sites.setup', $this->site));

        // The NasDriver methods the core may still call answer with notes.
        $driver = NasDrivers::make('unifi');
        $this->assertInstanceOf(AuthorizesViaController::class, $driver);
        $this->assertSame('Ubiquiti UniFi (experimental)', $driver->label());
        $this->assertSame([], $driver->replyAttributes(new Grant));
        $commands = $driver->setupCommands($this->site, []);
        $this->assertArrayHasKey('verify', $commands['diagnostics']);
        $this->assertArrayHasKey('uninstall_network', $commands['uninstall']);
    }

    public function test_secrets_survive_a_data_key_rotation_and_controller_is_a_security_setting(): void
    {
        $this->configure(['mode' => UnifiSettings::MODE_LEGACY, 'username' => 'portal']);
        $before = $this->site->fresh()->setting('controller.api_key');

        app(FederatedSecrets::class)->rotate();

        $site = $this->site->fresh();
        $this->assertNotSame($before, $site->setting('controller.api_key'));
        $this->assertSame(self::API_KEY, UnifiSettings::secret($site, 'api_key'));
        $this->assertSame(self::PASSWORD, UnifiSettings::secret($site, 'password'));
        $this->assertContains('controller', Federation::SECURITY_SITE_SETTINGS);
    }

    // --- Helpers ------------------------------------------------------------------------------------

    private function driver(): UnifiDriver
    {
        return app(UnifiDriver::class);
    }

    private function configure(array $overrides = []): void
    {
        $config = array_merge(['url' => self::URL, 'mode' => UnifiSettings::MODE_API_KEY, 'unifi_site' => 'default', 'verify_tls' => true], $overrides);
        $this->site->update(['settings' => UnifiSettings::mergeInto($this->site->fresh(), $config,
            ['api_key' => self::API_KEY, 'password' => self::PASSWORD])]);
        $this->site->refresh();
        $this->app->forgetScopedInstances();
    }

    private function connect(string $mac, array $values = []): void
    {
        $this->clients[strtolower($mac)] = $values + ['id' => 'client-1', 'guest' => true, 'authorized' => false, 'ip' => '10.0.50.10',
            'device' => 'device-1'];
    }

    private function context(string $mac = self::MAC, ?string $ap = self::AP): HotspotContext
    {
        return new HotspotContext($mac, null, null, null, $ap, 'Guest WiFi', 'default');
    }

    private function grant(int $duration = 3600): Grant
    {
        $grant = app(GrantManager::class)->createCode(['duration_s' => $duration, 'expiry_mode' => ExpiryMode::FirstLogin]);
        $grant->update(['first_login_at' => now(), 'expires_at' => now()->addSeconds($duration)]);

        return $grant->fresh();
    }

    /** Fake official Network API (OpenAPI 10.6.106 shapes). */
    private function fakeOfficial(): void
    {
        Http::fake(function (Request $request) {
            if (($status = array_shift($this->preface)) !== null) {
                return Http::response(['statusCode' => $status], $status);
            }
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $base = '/proxy/network/integration/v1';
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match (true) {
                $path === "$base/info" => Http::response(['applicationVersion' => '10.0.162']),
                $path === "$base/sites" => Http::response(['offset' => 0, 'limit' => 200, 'count' => 1, 'totalCount' => 1,
                    'data' => [['id' => 'site-uuid', 'name' => 'Default', 'internalReference' => 'default']]]),
                $path === "$base/sites/site-uuid/clients" && isset($query['filter']) => Http::response(['count' => 0, 'totalCount' => 0,
                    'offset' => 0, 'limit' => 1, 'data' => $this->officialRows($query['filter'])]),
                $path === "$base/sites/site-uuid/clients" => Http::response(['count' => 1, 'totalCount' => count($this->clients), 'offset' => 0, 'limit' => 1, 'data' => []]),
                $path === "$base/sites/site-uuid/clients/client-1" => Http::response($this->officialDetails()),
                $path === "$base/sites/site-uuid/devices/device-1" => Http::response(['id' => 'device-1', 'macAddress' => strtolower(self::AP)]),
                $path === "$base/sites/site-uuid/clients/client-1/actions" && $request['action'] === 'AUTHORIZE_GUEST_ACCESS' => Http::response([
                    'action' => 'AUTHORIZE_GUEST_ACCESS', 'grantedAuthorization' => ['authorizationMethod' => 'API',
                        'authorizedAt' => '2026-11-04T10:00:00Z', 'expiresAt' => '2026-11-04T12:00:00Z']]),
                $path === "$base/sites/site-uuid/clients/client-1/actions" => Http::response(['action' => 'UNAUTHORIZE_GUEST_ACCESS',
                    'revokedAuthorization' => ['authorizationMethod' => 'API', 'authorizedAt' => '2026-11-04T10:00:00Z', 'expiresAt' => '2026-11-04T12:00:00Z']]),
                default => Http::response(['statusCode' => 404, 'code' => 'api.not-found', 'message' => 'Not found'], 404),
            };
        });
    }

    private function officialRows(string $filter): array
    {
        preg_match("/macAddress\\.eq\\('([0-9a-f:]+)'\\)/", $filter, $m);
        $client = $this->clients[$m[1] ?? ''] ?? null;

        return $client === null ? [] : [['type' => 'WIRELESS', 'id' => $client['id'], 'name' => 'Phone', 'macAddress' => $m[1],
            'uplinkDeviceId' => $client['device'], 'access' => ['type' => $client['guest'] ? 'GUEST' : 'DEFAULT', 'authorized' => $client['authorized']]]];
    }

    private function officialDetails(): array
    {
        $client = $this->clients[strtolower(self::MAC)];

        return ['type' => 'WIRELESS', 'id' => $client['id'], 'name' => 'Phone', 'macAddress' => strtolower(self::MAC),
            'ipAddress' => $client['ip'], 'uplinkDeviceId' => $client['device'],
            'access' => ['type' => $client['guest'] ? 'GUEST' : 'DEFAULT', 'authorized' => $client['authorized'],
                'authorization' => ['authorizationMethod' => 'API', 'authorizedAt' => '2026-11-04T10:00:00Z', 'expiresAt' => '2026-11-04T12:00:00Z',
                    'usage' => ['bytes' => ($client['rx'] ?? 0) + ($client['tx'] ?? 0), 'rxBytes' => $client['rx'] ?? 0,
                        'txBytes' => $client['tx'] ?? 0, 'durationSec' => 60]]]];
    }

    /** Fake legacy controller: standalone (/api/login) or UniFi OS (/api/auth/login, /proxy/network). */
    private function fakeLegacy(bool $unifiOs = false): void
    {
        $prefix = $unifiOs ? '/proxy/network' : '';
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.'.rtrim(strtr(base64_encode(json_encode(['csrfToken' => 'csrf-os'])), '+/', '-_'), '=').'.sig';
        Http::fake(function (Request $request) use ($unifiOs, $prefix, $jwt) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $ok = fn (array $data) => Http::response(['meta' => ['rc' => 'ok'], 'data' => $data]);

            return match (true) {
                $path === '/api/login' && ! $unifiOs => Http::response(['meta' => ['rc' => 'ok'], 'data' => []], 200,
                    ['Set-Cookie' => ['unifises=session-1; path=/; HttpOnly', 'csrf_token=csrf-1; path=/']]),
                $path === '/api/auth/login' && $unifiOs => Http::response(['unique_id' => 'u1'], 200,
                    ['Set-Cookie' => ['TOKEN='.$jwt.'; path=/; HttpOnly']]),
                $path === "$prefix/api/self/sites" => $ok([['_id' => 's1', 'name' => 'default', 'desc' => 'Default']]),
                str_starts_with($path, "$prefix/api/s/default/stat/sta/") => $ok(($c = $this->clients[substr($path, strlen("$prefix/api/s/default/stat/sta/"))] ?? null)
                    ? [['mac' => strtolower(self::MAC), 'is_guest' => $c['guest'], 'authorized' => $c['authorized'], 'ap_mac' => strtolower(self::AP),
                        'ip' => $c['ip'], 'tx_bytes' => 5000, 'rx_bytes' => 700]] : []),
                $path === "$prefix/api/s/default/cmd/stamgr" => $ok($request['cmd'] === 'authorize-guest'
                    ? [['mac' => $request['mac'], 'start' => 1_799_996_400, 'end' => 1_800_000_000, 'authorized_by' => 'api']] : []),
                default => Http::response('<html>Not found</html>', 404),
            };
        });
    }

    /** Form input of the controller section (overrides by field name). */
    private static function input(array $overrides = []): array
    {
        return array_merge(['url' => self::URL, 'mode' => 'api_key', 'api_key' => '', 'username' => '', 'password' => '',
            'unifi_site' => 'default', 'verify_tls' => '1', 'tls_pin' => '', 'duration_default_s' => '86400'], $overrides);
    }

    private function saveController(User $admin, array $overrides): TestResponse
    {
        return $this->admin($admin)->put("/admin/sites/{$this->site->id}/controller", ['controller' => self::input($overrides)]);
    }

    private function admin(User $user): static
    {
        return $this->onZone('admin')->actingAs($user);
    }

    private function guest(): static
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en');
    }

    private function login(string $code): TestResponse
    {
        $this->guest()->get('/portal?'.http_build_query(['site' => 'site-u', 'unifi_site' => 'default', 'id' => self::MAC,
            'ap' => self::AP, 'url' => 'http://www.example.org/news', 'ssid' => 'Guest WiFi']))->assertOk();

        return $this->guest()->post('/portal/code', ['first_name' => 'Test', 'last_name' => 'Guest', 'email' => 'guest@example.com',
            'terms' => '1', 'code' => $code, 'terms_version' => TermsVersion::current('en')?->id]);
    }
}
