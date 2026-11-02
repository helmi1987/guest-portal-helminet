<?php

namespace Tests\Feature;

use App\Jobs\QueryRouterState;
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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Unit\RouterOs\FakeApiConnection;

/**
 * Router state query of the setup assistant (CONCEPT.md §9, decision 2026-10-31): read-only API access
 * per router, comparison per block, prefill of empty inputs, greyed-out commands while stale, errors.
 */
class RouterStateTest extends TestCase
{
    use RefreshDatabase;

    private const NETWORK = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24',
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
            '/interface vlan' => [['name' => 'vlan-guest', 'interface' => 'bridge1', 'vlan-id' => '30', 'comment' => $k('net', 'vlan')]],
            '/ip address' => [['address' => '10.30.0.1/24', 'interface' => 'vlan-guest', 'comment' => $k('net', 'gateway')]],
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
        // Local to the instance: not part of the replication payload at all
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

    public function test_pin_changes_clear_only_the_error_and_are_audited_with_the_pinned_flag(): void
    {
        $otherPin = 'sha256//BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';
        $lastAudit = fn () => AuditLog::query()->where('action', 'router.api_access_updated')->latest('id')->first()->data;
        $pinRemovedAudits = fn () => AuditLog::query()->where('action', 'router.api_pin_removed')->count();
        $tlsError = fn () => $this->nas->forceFill(['router_state_error' => ['kind' => 'tls', 'detail' => 'pin']])->save();

        $this->configureApi();
        $this->assertTrue($lastAudit()['pinned']);
        $this->nas->storeRouterState(['menus' => [], 'host' => '10.0.1.1']);
        $stateAt = $this->nas->fresh()->router_state_at;
        $tlsError();

        // Unchanged pin keeps the error (and the state)
        $this->configureApi(['api_password' => '']);
        $this->assertSame('tls', $this->nas->router_state_error['kind']);
        $this->assertSame(['menus' => [], 'host' => '10.0.1.1'], $this->nas->router_state);

        // Pin changed to another value: only the error is cleared
        $this->configureApi(['api_password' => '', 'api_pin' => $otherPin]);
        $this->assertSame($otherPin, $this->nas->api_pin);
        $this->assertNull($this->nas->router_state_error);
        $this->assertSame(['menus' => [], 'host' => '10.0.1.1'], $this->nas->router_state);
        $this->assertTrue($stateAt->equalTo($this->nas->router_state_at));
        $this->assertTrue($lastAudit()['pinned']);
        $this->assertSame(0, $pinRemovedAudits());

        // Pin removed: state and its time are kept, the error is cleared, audited once
        $tlsError();
        $this->configureApi(['api_password' => '', 'api_pin' => $otherPin, 'api_clear_pin' => '1']);
        $this->assertNull($this->nas->api_pin);
        $this->assertNull($this->nas->router_state_error);
        $this->assertSame(['menus' => [], 'host' => '10.0.1.1'], $this->nas->router_state);
        $this->assertTrue($stateAt->equalTo($this->nas->router_state_at));
        $this->assertFalse($lastAudit()['pinned']);
        $this->assertSame(1, $pinRemovedAudits());

        // Checkbox without a stored pin: no "pin removed" audit, the error stays
        $tlsError();
        $this->configureApi(['api_password' => '', 'api_pin' => '', 'api_clear_pin' => '1']);
        $this->assertSame(1, $pinRemovedAudits());
        $this->assertSame('tls', $this->nas->router_state_error['kind']);
        $this->assertFalse($lastAudit()['pinned']);
    }

    public function test_query_compares_blocks_and_ungreys_the_commands(): void
    {
        $this->saveNetwork();
        // Without API access nothing is greyed out (no state possible)
        $this->assertStringNotContainsString('data-cli-disabled', $this->page());

        $this->configureApi();
        $before = $this->page();
        $this->assertStringContainsString('data-cli-disabled-notice', $before);
        $this->assertMatchesRegularExpression('/<div x-data="gpScript\([^"]*\)" class="mb-4 opacity-50 select-none"\s+aria-disabled="true" data-cli-disabled/', $before);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn-light" disabled\s+aria-disabled="true"/', $before);
        // The access block itself is never greyed out
        $this->assertMatchesRegularExpression('/<div x-data="gpScript\([^"]*\)" class="mb-4"\s*>\s*<div[^>]*>\s*<span class="text-sm font-medium">'
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
        $this->assertStringContainsString('<code>vlan-guest</code>', $page);

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
        foreach (['vlan_id' => '30', 'interface' => 'vlan-guest', 'bridge' => 'bridge1', 'gateway' => '10.30.0.1', 'subnet' => '10.30.0.0/24',
            'ssid' => 'Guest-WiFi'] as $field => $value) {
            $this->assertMatchesRegularExpression('/name="'.$field.'"[^>]*value="'.preg_quote($value, '/').'"/', $page, $field);
            $this->assertStringContainsString('data-derived="'.$field.'"', $page);
        }
        $this->assertStringContainsString('data-derived="wifi_modes"', $page);
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
        $this->assertMatchesRegularExpression('/name="interface"[^>]*value="vlan-guest"/', $page);
        $this->assertMatchesRegularExpression('/name="wifi_modes\[\]" value="capsman2" x-model="wifiModes"\s+checked/', $page);
        $this->assertStringContainsString(e(trans('portal.router_api.derived_from.own', ['router' => 'Router B'])), $page);
        // A field only router A provides comes from router A
        $this->assertMatchesRegularExpression('/<option value="flash\/hotspot" selected>/', $page);
        $this->assertStringContainsString(e(trans('portal.router_api.derived_from.default_profile', ['router' => 'Router A'])), $page);
        $this->assertStringContainsString(e(trans('portal.router_api.derived_help', ['router' => 'Router A, Router B'])), $page);

        // Without router B the assumptions of router A are used
        $second->storeRouterState(null, ['kind' => 'connect', 'detail' => 'down']);
        $page = $this->page();
        $this->assertMatchesRegularExpression('/name="bridge"[^>]*value="bridge-a"/', $page);
        $this->assertMatchesRegularExpression('/name="wifi_modes\[\]" value="capsman1" x-model="wifiModes"\s+checked/', $page);
        $this->assertStringNotContainsString('data-derived="interface"', $page);
    }

    public function test_refresh_queues_one_low_priority_job_per_router_and_is_throttled(): void
    {
        $this->configureApi();
        $second = NasClient::find($this->site->nasClients()->create(['name' => 'Router B', 'nas_ip' => '10.0.1.2', 'coa_port' => 3799])->id);
        $second->forceFill(['api_host' => '10.0.1.2', 'api_type' => 'rest', 'api_user' => 'portal-ro', 'api_password' => 'x', 'api_pin' => self::PIN])->save();
        // Router without API access gets no job
        $this->site->nasClients()->create(['name' => 'Router C', 'nas_ip' => '10.0.1.3', 'coa_port' => 3799]);

        Queue::fake();
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/state")->assertRedirect()
            ->assertSessionHas('status', trans('portal.router_api.refresh_queued'));
        Queue::assertPushedOn(QueryRouterState::QUEUE, QueryRouterState::class);
        Queue::assertPushed(QueryRouterState::class, 2);
        foreach ([$this->nas, $second] as $nas) {
            Queue::assertPushed(QueryRouterState::class, fn (QueryRouterState $job) => $job->nasId === (string) $nas->id
                && $job->timeout > (int) config('portal.router_api.deadline'));
        }
        // The page shows the routers still queued
        $page = $this->page();
        $this->assertSame(2, substr_count($page, 'data-state-queued'));
        $this->assertStringContainsString(e(trans('portal.router_api.reload')), $page);

        // The job queries the router and removes the marker
        $this->fakeRest($this->routerMenus());
        app()->call([new QueryRouterState((string) $this->nas->id), 'handle']);
        $this->assertNull($this->nas->fresh()->router_state_error);
        $this->assertNotNull($this->nas->fresh()->router_state);
        $this->assertNull(QueryRouterState::queuedAt((string) $this->nas->id));
        $this->assertSame(1, substr_count($this->page(), 'data-state-queued'));
        // A failed job removes the marker too
        (new QueryRouterState((string) $second->id))->failed();
        $this->assertStringNotContainsString('data-state-queued', $this->page());

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
        // The uninstall never changes /ip service: services still using the portal's certificate are only
        // reported, before the certificates are removed
        $this->assertDoesNotMatchRegularExpression('/^\s*[^#\s].*\/ip service set/m', $uninstall);
        $report = strpos($uninstall, ':foreach gpSvc in=[/ip service find where certificate="guest-portal"] do={ :put');
        $this->assertNotFalse($report);
        $this->assertLessThan(strpos($uninstall, '/certificate remove'), $report);
        // Every menu of the generated objects is read by the state query
        $menus = array_keys($driver->stateMenus($site));
        foreach ($driver->managedObjects($site) as $objects) {
            foreach ($objects as $object) {
                $this->assertContains($object['path'], $menus);
            }
        }
        $this->assertEqualsCanonicalizing($menus, array_intersect($menus, Menus::ALLOWED));
    }

    /** Line text => display state of the coloured script lines of a page. */
    private function lineStates(string $page): array
    {
        preg_match_all('/<span data-line class="gp-line gp-line-([a-z]+)" data-line-status="\1" title="[^"]*">([^<]*)<\/span>/', $page, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $status, $text]) {
            $out[html_entity_decode($text, ENT_QUOTES)][] = $status;
        }

        return $out;
    }

    public function test_script_lines_are_coloured_by_the_fresh_router_state(): void
    {
        $this->saveNetwork();
        $this->configureApi();
        $this->fakeRest($this->routerMenus());
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();

        $page = $this->page();
        $this->assertStringContainsString('data-line-legend', $page);
        $lines = $this->lineStates($page);
        $state = function (string $needle) use ($lines): array {
            $hits = array_values(array_filter($lines, fn ($text) => str_contains($text, $needle), ARRAY_FILTER_USE_KEY));

            return array_values(array_unique(array_merge(...$hits ?: [[]])));
        };
        // present = ok, also the add/else/closing lines of the add-or-update group
        $this->assertSame(['ok'], $state('/interface vlan add name=vlan-guest interface=bridge1 vlan-id=30'));
        $this->assertSame(['update'], $state('/ip pool add name=guest-pool'));
        $this->assertSame(['update'], $state('/ip dhcp-server add name=guest-dhcp'));
        $this->assertSame(['missing'], $state('address=172.16.0.0/12'));
        // Hotspot profile by name (differs), the continuation line inherits
        $this->assertSame(['update'], $state('add name=guest-portal dns-name='));
        $this->assertSame(['update'], $state('use-radius=yes radius-accounting=yes'));
        // Access block per router: user missing, group present
        $this->assertSame(['missing'], $state('/user add name=portal-ro group=guest-portal-ro'));
        $this->assertSame(['ok'], $state('/user group add name=guest-portal-ro'));
        // Comment lines stay neutral; the uninstall marks the existing own VLAN as "will be removed"
        $this->assertSame([], array_filter(array_keys($lines), fn ($text) => str_starts_with(ltrim($text), '#')));
        $this->assertSame(['remove'], $state('remove [find where name=vlan-guest'));

        // Stale state: no colours (the commands are greyed out)
        $this->travel(16)->minutes();
        $page = $this->page();
        $this->assertStringContainsString('data-cli-disabled-notice', $page);
        $this->assertStringNotContainsString('data-line-legend', $page);
        $this->assertStringNotContainsString('data-line-status', preg_replace('/<div[^>]*data-router-state.*?<\/section>/s', '', $page));
    }

    public function test_remove_lines_are_coloured_only_when_the_following_commands_need_action(): void
    {
        $driver = $this->site->driver();
        $script = implode("\n", [
            '/ip hotspot',
            'remove [find name="guest-portal"]',
            '/ip hotspot profile',
            'remove [find name="guest-portal"]',
            'add name=guest-portal dns-name=x',
            '/ip hotspot walled-garden',
            'remove [find where (comment="guest-portal:walled:portal-host" || comment="guest-portal")]',
            'add dst-host=p action=allow comment="guest-portal:walled:portal-host"',
            '/ip hotspot add name=guest-portal interface=vlan-guest',
            '/ip dns static',
            'remove [find where (comment="guest-portal:dns:a" || comment="guest-portal:dns:b")]',
            'add name=a comment="guest-portal:dns:a"',
            'add name=b comment="guest-portal:dns:b"',
        ]);
        $status = fn (array $byKey) => $driver->lineStatuses($script, $byKey);
        // Everything ok: no remove line is coloured
        $ok = $status(['hotspot:server' => 'present', 'hotspot:profile' => 'present', 'guest-portal:walled:portal-host' => 'present',
            'guest-portal:dns:a' => 'present', 'guest-portal:dns:b' => 'present']);
        $this->assertSame([null, null, null, null, 'ok', null, null, 'ok', 'ok', null, null, 'ok', 'ok'], $ok);
        // A remove line takes the worst state of the later commands of its menu (not of its own keys)
        $todo = $status(['hotspot:server' => 'missing', 'hotspot:profile' => 'differs', 'guest-portal:walled:portal-host' => 'present',
            'guest-portal:dns:a' => 'present', 'guest-portal:dns:b' => 'missing']);
        $this->assertSame([null, 'missing', null, 'update', 'update', null, null, 'ok', 'missing', null, 'missing', 'ok', 'missing'], $todo);
    }

    public function test_uninstall_lines_show_what_would_be_removed(): void
    {
        $driver = $this->site->driver();
        $script = implode("\n", [
            '# comment',
            '/system scheduler remove [find where name="guest-portal-cert" && (comment="guest-portal:cert:scheduler" || !comment)]',
            '/ip hotspot remove [find where name="guest-portal"]',
            '/ip hotspot profile remove [find where name="guest-portal"]',
            '/radius remove [find where (comment="guest-portal:radius:server" || comment="guest-portal")]',
            '/ip dhcp-server remove [find where name=guest-dhcp && (comment="guest-portal:net:dhcp-server" || comment="guest-portal")]',
            '/interface vlan remove [find where name=vlan-guest && (comment="guest-portal:net:vlan" || comment="guest-portal")]',
            '/certificate remove [find where name="guest-portal"]',
            ':foreach gpNet in=[/ip dhcp-server network find where (comment="guest-portal:net:dhcp-network")] do={',
            '    /ip dhcp-server network set $gpNet dhcp-option=""',
            '}',
            ':if (1 = 1) do={ /interface list remove [find where name=GUEST && (comment="guest-portal:fw:list-guest")] }',
        ]);
        $status = $driver->lineStatuses($script, ['guest-portal:cert:scheduler' => 'unmanaged', 'hotspot:server' => 'differs',
            'hotspot:profile' => 'missing', 'guest-portal:radius:server' => 'present', 'guest-portal:net:dhcp-server' => 'unmanaged',
            'guest-portal:net:vlan' => 'missing', 'guest-portal:net:dhcp-network' => 'present', 'guest-portal:fw:list-guest' => 'present'], true);
        // Own object exists: remove; only an object without comment: kept (except "|| !comment"); nothing or unknown: null
        // Only lines that delete: a foreach that only resets an option stays neutral, a remove inside :if counts
        $this->assertSame([null, 'remove', 'remove', null, 'remove', 'kept', null, null, null, null, null, 'remove'], $status);
    }

    public function test_the_legend_lists_only_the_states_of_its_block(): void
    {
        $html = Blade::render('<x-admin.code :code="$code" :lines="$lines" />', ['code' => "a\nb", 'lines' => ['ok', null]]);
        $this->assertStringContainsString('data-line-legend', $html);
        $this->assertStringContainsString('gp-badge-ok', $html);
        $this->assertStringNotContainsString('gp-badge-error', $html);
        $this->assertStringNotContainsString('gp-badge-warn', $html);
        $this->assertStringNotContainsString('gp-badge-unmanaged', $html);
    }

    public function test_objects_without_comment_are_unmanaged_with_a_one_time_adopt_command(): void
    {
        $this->saveNetwork();
        $this->configureApi();
        // Wi-Fi objects as created by 0.6.0 to 0.12.6 (no comment), the VLAN interface by hand; the pool has
        // a foreign comment (never adopted), the cert script without comment is replaced by the router script
        $menus = ['/interface wifi datapath' => [['name' => 'dp-guest', 'bridge' => 'bridge1', 'vlan-id' => '30']],
            '/interface wifi security' => [['name' => 'sec-guest', 'comment' => '']],
            '/interface wifi configuration' => [['name' => 'cfg-guest', 'ssid' => 'Guest-WiFi']],
            '/interface vlan' => [['name' => 'vlan-guest', 'interface' => 'bridge1', 'vlan-id' => '30']],
            '/ip pool' => [['name' => 'guest-pool', 'ranges' => '10.30.0.10-10.30.0.250', 'comment' => 'my pool']],
            '/system script' => [['name' => 'guest-portal-cert']],
            // Two GUEST-like rows without comment: guarded list = foreign; two veth rows = ambiguous
            '/interface list' => [['name' => 'GUEST']],
            '/interface veth' => [['name' => 'veth-portal'], ['name' => 'veth-portal']],
        ] + $this->routerMenus();
        $this->fakeRest($menus);
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();

        $site = Site::with('nasClients')->find($this->site->id);
        $compare = $site->driver()->compareState($site, $this->nas->fresh()->router_state);
        $wifi = $compare['wifi'];
        foreach (['dp-guest' => 'datapath', 'sec-guest' => 'security', 'cfg-guest' => 'configuration'] as $name => $menu) {
            $this->assertSame('unmanaged', $wifi["guest-portal:wifi:{$name}"]['status']);
            $this->assertSame("/interface wifi {$menu} set [find where name=\"{$name}\" && !comment] comment=\"guest-portal:wifi:{$name}\"",
                $wifi["guest-portal:wifi:{$name}"]['adopt']);
        }
        $this->assertSame('unmanaged', $compare['net']['guest-portal:net:vlan']['status']);
        $this->assertSame('missing', $compare['net']['guest-portal:net:pool']['status']);
        $this->assertArrayNotHasKey('adopt', $compare['net']['guest-portal:net:pool']);
        $this->assertSame('legacy', $compare['cert']['guest-portal:cert:script']['status']);
        $this->assertSame('foreign', $compare['fw']['guest-portal:fw:list-guest']['status']);
        $this->assertSame('unmanaged', $compare['container']['guest-portal:container:veth']['status']);
        $this->assertNull($compare['container']['guest-portal:container:veth']['adopt']);

        $page = $this->page();
        $this->assertStringContainsString('data-state-item="guest-portal:wifi:dp-guest" data-status="unmanaged"', $page);
        $this->assertStringContainsString('gp-badge gp-badge-unmanaged', $page);
        $this->assertStringContainsString('data-adopt-commands="'.$this->nas->id.'"', $page);
        $this->assertStringContainsString(e('/interface wifi datapath set [find where name="dp-guest" && !comment] comment="guest-portal:wifi:dp-guest"'), $page);
        $this->assertStringContainsString(e('/interface vlan set [find where name="vlan-guest" && !comment] comment="guest-portal:net:vlan"'), $page);
        $this->assertStringContainsString(e(__('portal.router_api.adopt_ambiguous')), $page);
        // The add lines of the blocks are marked (the add fails until adopted), with the legend entry
        $lines = $this->lineStates($page);
        $hit = fn (string $needle) => array_values(array_unique(array_merge(...array_values(array_filter($lines, fn ($t) => str_contains($t, $needle), ARRAY_FILTER_USE_KEY)) ?: [[]])));
        $this->assertSame(['unmanaged'], $hit('/interface wifi datapath add name=dp-guest'));
        $this->assertSame(['unmanaged'], $hit('/interface vlan add name=vlan-guest'));
        $this->assertStringContainsString('<span class="gp-badge gp-badge-unmanaged">'.e(__('portal.setup.script.line_status.unmanaged')).'</span>', $page);
        // The scripts themselves never adopt: no set on a row with an empty comment
        $commands = $site->driver()->setupCommands($site, []);
        $all = '';
        array_walk_recursive($commands, function ($rsc) use (&$all) {
            $all .= $rsc."\n";
        });
        $this->assertStringContainsString('/interface wifi datapath add name=dp-guest', $all);
        $this->assertStringNotContainsString('&& !comment]', $all);
    }

    public function test_dynamic_hotspot_entries_are_never_own_legacy_or_unmanaged(): void
    {
        $this->saveNetwork();
        $this->configureApi();
        // Dynamic entries RouterOS adds for the hotspot (decision 2026-11-02): walled-garden copy of a
        // walled-garden ip entry with its legacy comment "guest-portal", the A entry of the hotspot dns-name;
        // a dynamic row with the name of an own entry and no comment would otherwise be "unmanaged"
        $menus = [
            '/ip hotspot walled-garden' => [['action' => 'allow', 'comment' => 'guest-portal', 'dynamic' => 'true']],
            '/ip hotspot walled-garden ip' => [['dst-address' => '10.0.0.10', 'protocol' => 'tcp', 'dst-port' => '443', 'action' => 'accept',
                'comment' => 'guest-portal', 'dynamic' => 'true']],
            '/ip dns static' => [['name' => 'hotspot-a.guest.example.com', 'address' => '10.30.0.1', 'dynamic' => 'true'],
                ['name' => 'guest-portal-v4.internal', 'address' => '10.0.0.10', 'dynamic' => 'true']],
        ] + $this->routerMenus();
        $this->fakeRest($menus);
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();
        foreach (['ip/hotspot/walled-garden', 'ip/hotspot/walled-garden/ip', 'ip/dns/static'] as $path) {
            Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/rest/{$path}/print") && in_array('dynamic', $r->data()['.proplist'], true));
        }

        $site = Site::with('nasClients')->find($this->site->id);
        $compare = $site->driver()->compareState($site, $this->nas->fresh()->router_state);
        $this->assertSame('missing', $compare['walled']['guest-portal:walled:portal-host']['status']);
        $this->assertSame('missing', $compare['walled']['guest-portal:walled:portal-ip']['status']);
        $this->assertSame('missing', $compare['dns']['guest-portal:dns:portal-a']['status']);
        $this->assertArrayNotHasKey('adopt', $compare['dns']['guest-portal:dns:portal-a']);
        // No own hotspot-name entry any more: present by name, shown as dynamic
        $this->assertArrayNotHasKey('guest-portal:dns:hotspot-name', $compare['dns']);
        $this->assertSame(['path' => '/ip dns static', 'name' => 'hotspot-a.guest.example.com', 'status' => 'present', 'diff' => [], 'info' => 'dynamic'],
            $compare['dns']['dns:hotspot-name']);
        $page = $this->page();
        $this->assertStringContainsString('data-state-item="dns:hotspot-name" data-status="present"', $page);
        $this->assertStringContainsString('data-state-info="dynamic">'.e(__('portal.router_api.info.dynamic')).'</span>', $page);

        // Static rows: an own hotspot-name entry of earlier versions is legacy (removed by the DNS block),
        // a static row without comment is unmanaged and its adopt command skips dynamic rows
        $state = $this->nas->fresh()->router_state;
        $state['menus']['/ip dns static']['rows'] = [
            ['name' => 'hotspot-a.guest.example.com', 'address' => '10.30.0.1', 'comment' => 'guest-portal:dns:hotspot-name', 'dynamic' => 'false'],
            ['name' => 'guest-portal-v4.internal', 'address' => '10.0.0.10', 'dynamic' => 'false'],
        ];
        $compare = $site->driver()->compareState($site, $state);
        $this->assertSame('legacy', $compare['dns']['dns:hotspot-name']['status']);
        $this->assertArrayNotHasKey('info', $compare['dns']['dns:hotspot-name']);
        $this->assertSame('unmanaged', $compare['dns']['guest-portal:dns:portal-a']['status']);
        $this->assertSame('/ip dns static set [find where name="guest-portal-v4.internal" && !comment && !dynamic] comment="guest-portal:dns:portal-a"',
            $compare['dns']['guest-portal:dns:portal-a']['adopt']);
        // Hotspot without its dynamic entry: missing; unknown without a hotspot name
        $state['menus']['/ip dns static']['rows'] = [];
        $this->assertSame('missing', $site->driver()->compareState($site, $state)['dns']['dns:hotspot-name']['status']);
        unset($state['menus']['/ip dns static']);
        $this->assertSame('unknown', $site->driver()->compareState($site, $state)['dns']['dns:hotspot-name']['status']);
    }

    public function test_placeholders_get_inputs_with_suggestions_from_the_router_state(): void
    {
        $this->saveNetwork();
        $this->configureApi();
        $this->fakeRest(['/interface' => [['name' => 'ether1', 'type' => 'ether'], ['name' => 'ether2', 'type' => 'ether'],
            ['name' => 'bridge1', 'type' => 'bridge']], '/interface wifi configuration' => [['name' => 'cfg-main', 'ssid' => 'Office'],
                ['name' => 'cfg-guest', 'ssid' => 'Guest-WiFi']], '/interface wifi provisioning' => [['master-configuration' => 'cfg-main',
                    'slave-configurations' => 'cfg-iot']]] + $this->routerMenus());
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state")->assertRedirect();

        $page = $this->page();
        $this->assertMatchesRegularExpression('/data-placeholder="TRUNK_PORT_TO_CAP"\s+list="(gp-code-[a-z0-9]+-trunk_port_to_cap)"/', $page);
        preg_match('/data-placeholder="TRUNK_PORT_TO_CAP"\s+list="([^"]+)"/', $page, $m);
        $this->assertMatchesRegularExpression('/<datalist id="'.preg_quote($m[1], '/').'">\s*<option value="ether1"><\/option><option value="ether2"><\/option>\s*<\/datalist>/', $page);
        // Own objects are not offered; free text without known values (no datalist)
        $this->assertMatchesRegularExpression('/data-placeholder="MASTER_CFG"\s+list="[^"]+"/', $page);
        $this->assertStringContainsString('<option value="cfg-main"></option>', $page);
        $this->assertStringNotContainsString('<option value="cfg-guest"></option>', $page);
        $this->assertMatchesRegularExpression('/data-placeholder="COUNTRY"\s+x-on:input/', $page);
        // The split guard operand is not a placeholder; the visible script keeps the placeholder text
        $this->assertStringContainsString('data-secret-placeholders="API_PASSWORD"', $page);
        $this->assertStringContainsString('password=&quot;&lt;API_PASSWORD&gt;&quot;', $page);

        // Router script page: placeholder inputs and colours of this router
        $config = $this->admin()->get("/admin/sites/{$this->site->id}/routers/{$this->nas->id}/config")->assertOk()->getContent();
        $this->assertStringContainsString('data-placeholder="LAN"', $config);
        $this->assertStringContainsString('<option value="10.30.0.0"></option>', $config);
        $radius = array_filter($this->lineStates($config), fn ($text) => str_contains($text, 'add address=10.0.0.10 secret='), ARRAY_FILTER_USE_KEY);
        $this->assertSame([['missing']], array_values($radius));
    }

    public function test_passwords_are_never_rendered_and_only_fetched_on_request(): void
    {
        $wifi = 'Wifi "pass" $1?';
        $this->saveNetwork(self::NETWORK + ['wifi_password' => $wifi]);
        $this->configureApi();

        $page = $this->page();
        foreach (['Test pass 1', 'Wifi', addcslashes($wifi, '\\"$?'), e(addcslashes($wifi, '\\"$?'))] as $secret) {
            $this->assertStringNotContainsString($secret, $page);
        }
        $this->assertStringContainsString('passphrase=&quot;&lt;WIFI_PASSWORD&gt;&quot;', $page);
        // A copy with the placeholder left in aborts before any change
        $this->assertStringContainsString(e(':if ("<WIFI_PASSWORD>" = ("<" . "WIFI_PASSWORD>")) do={ :error "replace <WIFI_PASSWORD> first" }'), $page);
        $this->assertSame(2, substr_count($page, 'data-insert-passwords'));
        // The generated script itself is unchanged (with the password)
        $this->assertStringContainsString('passphrase="'.addcslashes($wifi, '\\"$?').'"',
            $this->site->fresh()->driver()->setupCommands($this->site->fresh(), [])['network']['wifi']);

        // "Insert passwords": admin only, POST, not cached, escaped for the quoted RouterOS strings
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/secrets")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['WIFI_PASSWORD' => addcslashes($wifi, '\\"$?'), 'API_PASSWORD' => [(string) $this->nas->id => 'Test pass 1']]);
        $this->admin()->get("/admin/sites/{$this->site->id}/setup/secrets")->assertStatus(405);
        $this->onZone('admin')->actingAs(User::factory()->create())->post("/admin/sites/{$this->site->id}/setup/secrets")->assertForbidden();
        $this->onZone('guest')->post("/admin/sites/{$this->site->id}/setup/secrets")->assertNotFound();
        // Never audited
        $this->assertStringNotContainsString('Test pass 1', (string) json_encode(AuditLog::query()->get()->toArray()));
        $this->assertStringNotContainsString('Wifi', (string) json_encode(AuditLog::query()->get()->toArray()));
    }
}
