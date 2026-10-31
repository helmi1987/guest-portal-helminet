<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use App\Nas\MikrotikRos7Driver;
use App\Nas\RouterOs\ApiConnector;
use App\Nas\RouterOs\Menus;
use App\Nas\RouterOs\RouterOsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\RouterOs\FakeApiConnection;

/**
 * Router state query of the setup assistant (CONCEPT.md §9, decision 2026-10-31): read-only API access
 * per router, comparison per block, prefill of empty inputs, greyed-out commands while stale, errors.
 */
class RouterStateTest extends TestCase
{
    use RefreshDatabase;

    private const NETWORK = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan30-guest', 'subnet' => '10.30.0.0/24',
        'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2'];

    private const PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private Site $site;

    private NasClient $nas;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://portal.example.com']);
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot-a.guest.example.com'], 'radius_address' => '10.0.0.10']]);
        $this->nas = NasClient::find($this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.1.1', 'coa_port' => 3799])->id);
        $this->admin = User::factory()->admin()->create();
    }

    protected function admin(): static
    {
        return $this->onZone('admin')->actingAs($this->admin);
    }

    protected function configureApi(array $extra = []): void
    {
        $this->admin()->put("/admin/sites/{$this->site->id}/routers/{$this->nas->id}/api", $extra + ['api_host' => '10.0.1.1',
            'api_type' => 'rest', 'api_user' => 'portal-ro', 'api_password' => 'Test pass 1', 'api_pin' => self::PIN])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->nas->refresh();
    }

    protected function saveNetwork(array $values = self::NETWORK): void
    {
        $this->admin()->post("/admin/sites/{$this->site->id}/setup", $values)->assertSessionHasNoErrors();
    }

    /** Menu rows of a router with the assistant's objects (some legacy, some different). */
    protected function routerMenus(): array
    {
        $k = MikrotikRos7Driver::key(...);

        return [
            '/system resource' => [['version' => '7.24.5', 'board-name' => 'CHR']],
            '/interface bridge' => [['name' => 'bridge1', 'comment' => 'defconf']],
            '/interface vlan' => [['name' => 'vlan30-guest', 'interface' => 'bridge1', 'vlan-id' => '30', 'comment' => $k('net', 'vlan')]],
            '/ip address' => [['address' => '10.30.0.1/24', 'interface' => 'vlan30-guest', 'comment' => $k('net', 'gateway')]],
            // Legacy comment of scripts before the keys
            '/ip pool' => [['name' => 'guest-pool', 'ranges' => '10.30.0.10-10.30.0.250', 'comment' => 'guest-portal']],
            // Different interface than the assistant input
            '/ip dhcp-server' => [['name' => 'guest-dhcp', 'interface' => 'ether2', 'address-pool' => 'guest-pool', 'comment' => $k('net', 'dhcp-server')]],
            '/interface wifi configuration' => [['name' => 'cfg-guest', 'ssid' => 'Guest-WiFi', 'comment' => $k('wifi', 'cfg-guest')]],
            '/interface wifi capsman' => [['enabled' => 'true']],
            '/ip hotspot profile' => [['name' => 'default', 'html-directory' => 'flash/hotspot', 'default' => 'true'],
                ['name' => 'guest-portal', 'html-directory' => 'hotspot', 'dns-name' => 'hotspot-a.guest.example.com',
                    'hotspot-address' => '10.30.0.1', 'login-by' => 'mac', 'ssl-certificate' => 'none', 'default' => 'false']],
            '/user group' => [['name' => 'guest-portal-ro', 'policy' => 'read,api,rest-api,!local,!telnet', 'comment' => $k('api', 'group')]],
            '/user' => [],
            // Equal entry of others: the guarded add of the firewall block uses it
            '/ip firewall address-list' => [['list' => 'RFC1918', 'address' => '10.0.0.0/8', 'comment' => 'own lan list']],
            '/radius' => [['address' => '10.9.9.9', 'service' => 'hotspot', 'comment' => 'foreign']],
        ];
    }

    /** Fake REST router: every POST /rest/<menu>/print answers from $menus (others: no such command). */
    protected function fakeRest(array $menus): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($menus) {
            if (! preg_match('#^https://10\.0\.1\.1(?::443)?/rest/(.+)/print$#', $request->url(), $m) || $request->method() !== 'POST') {
                return Http::response(['error' => 400, 'detail' => 'unexpected'], 400);
            }
            $menu = '/'.str_replace('/', ' ', $m[1]);
            if (! array_key_exists($menu, $menus)) {
                return Http::response(['error' => 400, 'message' => 'Bad Request', 'detail' => 'no such command prefix'], 400);
            }
            $props = $request->data()['.proplist'];

            return Http::response(array_map(fn ($row) => array_intersect_key($row + ['.id' => '*1'], array_flip($props)), $menus[$menu]));
        });
    }

    protected function page(): string
    {
        return $this->admin()->get("/admin/sites/{$this->site->id}/setup")->assertOk()->getContent();
    }

    public function test_api_access_is_stored_encrypted_never_audited_flashed_or_rendered(): void
    {
        $this->admin()->put("/admin/sites/{$this->site->id}/routers/{$this->nas->id}/api", ['api_host' => '10.0.1.1', 'api_type' => 'rest',
            'api_port' => '99999', 'api_user' => 'portal-ro', 'api_password' => 'Test pass 1'])
            ->assertSessionHasErrorsIn('api_'.$this->nas->id, 'api_port');
        $old = (array) session('_old_input');
        $this->assertArrayNotHasKey('api_password', $old);
        $this->assertArrayNotHasKey('api_user', $old);

        $this->configureApi();
        $raw = (array) DB::table('nas_clients')->where('id', $this->nas->id)->first();
        $this->assertStringNotContainsString('Test pass 1', json_encode($raw));
        $this->assertStringNotContainsString('portal-ro', json_encode($raw));
        $this->assertSame('Test pass 1', $this->nas->api_password);
        $this->assertSame('portal-ro', $this->nas->api_user);
        $this->assertTrue($this->nas->hasApiAccess());
        $this->assertSame(443, $this->nas->apiPort());
        // Replicated with the router: the event payload carries only ciphertexts
        $payload = (string) DB::table('federation_events')->where('table', 'nas_clients')->orderByDesc('seq')->value('payload');
        $this->assertStringNotContainsString('Test pass 1', $payload);
        $this->assertStringNotContainsString('portal-ro', $payload);
        $audit = AuditLog::query()->where('action', 'router.api_access_updated')->latest('id')->first();
        $this->assertStringNotContainsString('Test pass 1', json_encode($audit->data));
        $this->assertStringNotContainsString('portal-ro', json_encode($audit->data));

        // Never rendered back; empty keeps it, the checkbox removes the access
        $edit = $this->admin()->get("/admin/sites/{$this->site->id}/edit")->assertOk()->assertSee(__('portal.router_api.password_stored'))->getContent();
        $this->assertStringNotContainsString('Test pass 1', $edit);
        $this->configureApi(['api_password' => '', 'api_type' => 'api-ssl']);
        $this->assertSame('Test pass 1', $this->nas->api_password);
        $this->assertSame(8729, $this->nas->apiPort());
        $this->admin()->put("/admin/sites/{$this->site->id}/routers/{$this->nas->id}/api", ['api_clear' => '1'])->assertRedirect();
        $this->assertFalse($this->nas->fresh()->hasApiAccess());
        $this->assertNull($this->nas->fresh()->api_password);
    }

    public function test_stored_pin_is_named_on_tls_error_and_can_be_removed_alone(): void
    {
        $this->configureApi();
        $this->nas->forceFill(['router_state_error' => ['kind' => 'tls', 'detail' => 'pin']])->save();

        foreach (['en', 'de'] as $locale) {
            $page = $this->admin()->withSession(['locale' => $locale])->get("/admin/sites/{$this->site->id}/setup")->assertOk()->getContent();
            $this->assertStringContainsString('data-state-error="tls"', $page);
            $this->assertStringContainsString(e(trans('portal.router_api.errors.tls_pinned', [], $locale)), $page, $locale);
        }

        // Removing the pin keeps the rest of the API access
        // and saves other changed fields of the same form submit
        $this->configureApi(['api_clear_pin' => '1', 'api_password' => '', 'api_user' => 'other-ro']);
        $this->assertNull($this->nas->api_pin);
        $this->assertNull($this->nas->router_state_error);
        $this->assertSame('other-ro', $this->nas->api_user);
        $this->assertSame('Test pass 1', $this->nas->api_password);
        $this->assertTrue($this->nas->hasApiAccess());
        $this->assertDatabaseHas('audit_logs', ['action' => 'router.api_pin_removed']);

        // The stale error is gone
        $page = $this->admin()->get("/admin/sites/{$this->site->id}/setup")->assertOk()->getContent();
        $this->assertStringNotContainsString('data-state-error', $page);
        $this->assertStringNotContainsString(e(trans('portal.router_api.errors.tls_pinned')), $page);
    }

    public function test_query_compares_blocks_and_ungreys_the_commands(): void
    {
        $this->saveNetwork();
        // Without API access nothing is greyed out (no state possible)
        $this->assertStringNotContainsString('data-cli-disabled', $this->page());

        $this->configureApi();
        $before = $this->page();
        $this->assertStringContainsString('data-cli-disabled-notice', $before);
        $this->assertMatchesRegularExpression('/<div x-data="\{ copied: false \}" class="mb-4 opacity-50 select-none"\s+aria-disabled="true" data-cli-disabled/', $before);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn-light" disabled\s+aria-disabled="true"/', $before);
        // The access block itself is never greyed out
        $this->assertMatchesRegularExpression('/<div x-data="\{ copied: false \}" class="mb-4"\s*>\s*<div[^>]*>\s*<span class="text-sm font-medium">'
            .preg_quote(e(__('portal.router_api.access_block', ['name' => 'Router A'])), '/').'/', $before);

        $this->fakeRest($this->routerMenus());
        $outbox = DB::table('federation_events')->count();
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")
            ->assertRedirect()->assertSessionHas('status');
        $nas = $this->nas->fresh();
        $this->assertNotNull($nas->router_state_at);
        $this->assertNull($nas->router_state_error);
        $this->assertSame('7.24.5', $nas->router_state['menus']['/system resource']['rows'][0]['version']);
        $this->assertArrayHasKey('error', $nas->router_state['menus']['/caps-man manager']);
        // Local status: no replication event
        $this->assertSame($outbox, DB::table('federation_events')->count());
        // Print only, explicit property lists, never secret properties
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/print') && $r->data()['.proplist'] !== []);
        Http::assertNotSent(fn (Request $r) => preg_match('/secret|pass|source/', json_encode($r->data())) === 1);

        $page = $this->page();
        $this->assertStringNotContainsString('data-cli-disabled', $page);
        $status = fn (string $key) => preg_match('/data-state-item="'.preg_quote($key, '/').'" data-status="([a-z]+)"/', $page, $m) ? $m[1] : null;
        $this->assertSame('present', $status('guest-portal:net:vlan'));
        $this->assertSame('present', $status('guest-portal:net:gateway'));
        $this->assertSame('legacy', $status('guest-portal:net:pool'));
        $this->assertSame('differs', $status('guest-portal:net:dhcp-server'));
        $this->assertSame('missing', $status('guest-portal:radius:server'));
        $this->assertSame('present', $status('guest-portal:api:group'));
        $this->assertSame('foreign', $status('guest-portal:fw:addr-rfc1918-10'));
        $this->assertSame('missing', $status('guest-portal:fw:addr-rfc1918-172'));
        $this->assertSame('missing', $status('guest-portal:api:user'));
        $this->assertSame('differs', $status('hotspot:profile'));
        $this->assertSame('present', $status('guest-portal:wifi:cfg-guest'));
        $this->assertSame('unknown', $status('guest-portal:container:veth'));
        $this->assertStringContainsString('<code>vlan30-guest</code>', $page);

        // Stale after max_age_minutes; "Refresh" re-queries
        $this->travel(16)->minutes();
        $this->assertStringContainsString('data-cli-disabled-notice', $this->page());
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/state")->assertRedirect()->assertSessionHas('status');
        $this->assertStringNotContainsString('data-cli-disabled', $this->page());

        // Stale after a change of the inputs
        $this->travel(1)->minutes();
        $this->saveNetwork(['ssid' => 'Guest-2'] + self::NETWORK);
        $this->assertStringContainsString('data-cli-disabled-notice', $this->page());
    }

    public function test_empty_inputs_are_prefilled_from_router_state(): void
    {
        $this->configureApi();
        $this->fakeRest($this->routerMenus());
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();

        $page = $this->page();
        foreach (['vlan_id' => '30', 'interface' => 'vlan30-guest', 'bridge' => 'bridge1', 'gateway' => '10.30.0.1', 'subnet' => '10.30.0.0/24',
            'ssid' => 'Guest-WiFi'] as $field => $value) {
            $this->assertMatchesRegularExpression('/name="'.$field.'"[^>]*value="'.preg_quote($value, '/').'"/', $page, $field);
            $this->assertStringContainsString('data-derived="'.$field.'"', $page);
        }
        $this->assertStringContainsString('data-derived="wifi_mode"', $page);
        // Only objects with the exact key are used (the pool has a legacy comment)
        $this->assertStringNotContainsString('data-derived="pool"', $page);
        // Own profile wins over the default profile
        $this->assertMatchesRegularExpression('/<option value="hotspot" selected>/', $page);
        // Commands use the derived values; nothing is saved
        $this->assertStringContainsString('interface=bridge1 vlan-id=30', $page);
        $this->assertSame([], (array) $this->site->fresh()->setting('setup', []));
        // Saved values are never replaced
        $this->saveNetwork(['interface' => 'vlan31-guest', 'vlan_id' => 31] + self::NETWORK);
        $page = $this->page();
        $this->assertStringNotContainsString('data-derived="interface"', $page);
        $this->assertStringNotContainsString('data-derived="vlan_id"', $page);
        $this->assertStringContainsString('data-derived="html_directory"', $page);
        $this->assertStringContainsString('interface=bridge1 vlan-id=31', $page);
    }

    public function test_prefill_needs_a_fresh_successful_state_of_the_current_host_and_clears_on_change(): void
    {
        $this->configureApi();
        $this->fakeRest($this->routerMenus());
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();
        $this->assertSame('10.0.1.1', $this->nas->fresh()->router_state['host']);
        $this->assertStringContainsString('data-derived="interface"', $this->page());

        // Stale state: no prefill
        $this->travel(16)->minutes();
        $this->assertStringNotContainsString('data-derived="interface"', $this->page());
        $this->travel(-16)->minutes();

        // Changed host (or type/port): state dropped
        $this->configureApi(['api_host' => '10.0.1.2']);
        $nas = $this->nas->fresh();
        $this->assertNull($nas->router_state);
        $this->assertNull($nas->router_state_at);
        $this->assertStringNotContainsString('data-derived="interface"', $this->page());

        // Unchanged settings keep the state; removing the access drops it
        $this->configureApi(['api_host' => '10.0.1.1']);
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();
        $this->configureApi(['api_host' => '10.0.1.1', 'api_password' => '']);
        $this->assertNotNull($this->nas->fresh()->router_state);
        $this->admin()->put("/admin/sites/{$this->site->id}/routers/{$this->nas->id}/api", ['api_clear' => 1])->assertRedirect();
        $this->assertNull($this->nas->fresh()->router_state);

        // Stored host that differs from the current one is not used (state written by hand)
        $this->configureApi();
        $this->nas->storeRouterState(['menus' => [], 'host' => '10.9.9.9']);
        $this->assertStringNotContainsString('data-derived', $this->page());
    }

    public function test_prefill_is_chosen_per_field_over_all_routers(): void
    {
        $this->configureApi();
        $second = NasClient::find($this->site->nasClients()->create(['name' => 'Router B', 'nas_ip' => '10.0.1.2', 'coa_port' => 3799])->id);
        $second->forceFill(['api_host' => '10.0.1.2', 'api_type' => 'rest', 'api_user' => 'portal-ro', 'api_password' => 'x', 'api_pin' => self::PIN])->save();
        $menus = $this->routerMenus();
        // Router A (first by name): no own objects, only assumptions (only bridge, enabled CAPsMAN, default profile)
        $this->nas->storeRouterState(['host' => '10.0.1.1', 'menus' => array_map(fn ($rows) => ['rows' => $rows], [
            '/interface bridge' => [['name' => 'bridge-a', 'comment' => 'defconf']],
            '/caps-man manager' => [['enabled' => 'true']],
            '/interface wifi capsman' => [['enabled' => 'false']],
            '/ip hotspot profile' => [['name' => 'default', 'html-directory' => 'flash/hotspot', 'default' => 'true']],
        ])]);
        // Router B: the assistant's own objects, no hotspot profile
        unset($menus['/ip hotspot profile']);
        $second->storeRouterState(['host' => '10.0.1.2', 'menus' => array_map(fn ($rows) => ['rows' => $rows], $menus)]);

        $page = $this->page();
        // Own objects of router B win over assumptions of router A
        $this->assertMatchesRegularExpression('/name="bridge"[^>]*value="bridge1"/', $page);
        $this->assertMatchesRegularExpression('/name="interface"[^>]*value="vlan30-guest"/', $page);
        $this->assertMatchesRegularExpression('/<option value="capsman2" selected>/', $page);
        $this->assertStringContainsString(e(trans('portal.router_api.derived_from.own', ['router' => 'Router B'])), $page);
        // A field only router A provides comes from router A
        $this->assertMatchesRegularExpression('/<option value="flash\/hotspot" selected>/', $page);
        $this->assertStringContainsString(e(trans('portal.router_api.derived_from.default_profile', ['router' => 'Router A'])), $page);
        $this->assertStringContainsString(e(trans('portal.router_api.derived_help', ['router' => 'Router A, Router B'])), $page);

        // Without router B the assumptions of router A are used
        $second->storeRouterState(null, ['kind' => 'connect', 'detail' => 'down']);
        $page = $this->page();
        $this->assertMatchesRegularExpression('/name="bridge"[^>]*value="bridge-a"/', $page);
        $this->assertMatchesRegularExpression('/<option value="capsman1" selected>/', $page);
        $this->assertStringNotContainsString('data-derived="interface"', $page);
    }

    public function test_refresh_uses_one_overall_deadline_and_is_throttled(): void
    {
        $this->configureApi();
        $second = NasClient::find($this->site->nasClients()->create(['name' => 'Router B', 'nas_ip' => '10.0.1.2', 'coa_port' => 3799])->id);
        $second->forceFill(['api_host' => '10.0.1.2', 'api_type' => 'rest', 'api_user' => 'portal-ro', 'api_password' => 'x', 'api_pin' => self::PIN])->save();
        $this->fakeRest($this->routerMenus());
        config(['portal.router_api.total_deadline' => 0]);
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/state")->assertRedirect();
        foreach ([$this->nas->fresh(), $second->fresh()] as $nas) {
            $this->assertSame('timeout', $nas->router_state_error['kind']);
            $this->assertSame('skipped: time limit', $nas->router_state_error['detail']);
        }
        Http::assertNothingSent();

        config(['portal.router_api.total_deadline' => 40]);
        for ($i = 0; $i < 12; $i++) {
            $response = $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state");
        }
        $response->assertStatus(429);
    }

    public function test_errors_are_shown_localized_per_router(): void
    {
        $this->configureApi();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => 401, 'message' => 'Unauthorized'], 401)]);
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();
        $this->assertSame('auth', $this->nas->fresh()->router_state_error['kind']);
        $this->assertNull($this->nas->fresh()->router_state_at);
        foreach (['de', 'en', 'fr', 'it'] as $locale) {
            $page = $this->admin()->withSession(['locale' => $locale])->get("/admin/sites/{$this->site->id}/setup")->assertOk()->getContent();
            $this->assertStringContainsString('data-state-error="auth"', $page);
            $this->assertStringContainsString(e(trans('portal.router_api.errors.auth', [], $locale)), $page, $locale);
            $this->assertStringContainsString('data-cli-disabled-notice', $page);
        }
        foreach (RouterOsException::KINDS as $kind) {
            foreach (['de', 'en', 'fr', 'it'] as $locale) {
                $this->assertNotSame('portal.router_api.errors.'.$kind, trans('portal.router_api.errors.'.$kind, [], $locale));
            }
        }

        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/state")->assertRedirect();
        $this->assertSame('timeout', $this->nas->fresh()->router_state_error['kind']);
    }

    public function test_api_ssl_query_through_the_socket_protocol(): void
    {
        $this->saveNetwork();
        $this->configureApi(['api_type' => 'api-ssl', 'api_port' => '8730']);
        $fake = new FakeApiConnection(FakeApiConnection::router('portal-ro', 'Test pass 1', $this->routerMenus()), self::PIN);
        $connector = FakeApiConnection::connector($fake);
        $this->app->instance(ApiConnector::class, $connector);

        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();
        $nas = $this->nas->fresh();
        $this->assertNull($nas->router_state_error);
        $this->assertSame(['10.0.1.1:8730'], $connector->opened);
        $this->assertSame('/login', $fake->received[0][0]);
        foreach (array_slice($fake->received, 1) as $sentence) {
            $this->assertMatchesRegularExpression('#^(/[a-z-]+)+/print$#', $sentence[0]);
        }
        $this->assertTrue($fake->closed);
        $this->assertStringNotContainsString('data-cli-disabled', $this->page());

        // Wrong pin: nothing sent, TLS error stored
        $other = new FakeApiConnection(FakeApiConnection::router('portal-ro', 'Test pass 1', []), 'sha256//BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=');
        $this->app->instance(ApiConnector::class, FakeApiConnection::connector($other));
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();
        $this->assertSame('tls', $this->nas->fresh()->router_state_error['kind']);
        $this->assertSame([], $other->received);
        // Previous state kept, but the commands are greyed out again
        $this->assertNotNull($this->nas->fresh()->router_state);
        $this->assertStringContainsString('data-cli-disabled-notice', $this->page());
    }

    public function test_access_commands_and_uninstall_cover_only_own_objects(): void
    {
        $this->saveNetwork();
        $this->configureApi();
        $site = Site::with('nasClients')->find($this->site->id);
        $driver = $site->driver();
        $access = $driver->apiAccessCommands($site, $this->nas->fresh());
        $this->assertStringContainsString('/user group add name=guest-portal-ro policy=read,api,rest-api comment="guest-portal:api:group"', $access);
        $this->assertStringContainsString('/user add name=portal-ro group=guest-portal-ro password="<API_PASSWORD>" comment="guest-portal:api:user"', $access);
        $this->assertStringContainsString('find where name=portal-ro && (comment="guest-portal:api:user"', $access);
        $this->assertStringNotContainsString('Test pass 1', $access);
        // Aborts while the placeholder is not replaced (the split operand survives a global replace)
        $this->assertStringContainsString(':if ("<API_PASSWORD>" = ("<" . "API_PASSWORD>")) do={ :error "replace <API_PASSWORD> first" }', $access);
        $this->assertLessThan(strpos($access, '/user group add'), strpos($access, ':error'));
        // /ip service is not changed (only printed, setting as a hint)
        $this->assertDoesNotMatchRegularExpression('/^\/ip service set/m', $access);
        $this->assertStringContainsString('# /ip service set www-ssl certificate=guest-portal', $access);
        $uninstall = $driver->setupCommands($site, [])['uninstall']['uninstall'];
        $this->assertStringContainsString('/user remove [find where group=guest-portal-ro && (comment="guest-portal:api:user"', $uninstall);
        $this->assertStringContainsString('/user group remove [find where name=guest-portal-ro && (comment="guest-portal:api:group"', $uninstall);
        // Every menu of the generated objects is read by the state query
        $menus = array_keys($driver->stateMenus($site));
        foreach ($driver->managedObjects($site) as $objects) {
            foreach ($objects as $object) {
                $this->assertContains($object['path'], $menus);
            }
        }
        $this->assertEqualsCanonicalizing($menus, array_intersect($menus, Menus::ALLOWED));
    }
}
