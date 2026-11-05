<?php

namespace Tests\Feature;

use App\Federation\Federation;
use App\Jobs\CheckRouters;
use App\Models\Channel;
use App\Models\Instance;
use App\Models\LocalSetting;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use App\Nas\MikrotikRos7Driver;
use App\Nas\RouterCheck;
use App\Nas\RouterFreshness;
use App\Nas\RouterNotifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Outdated routers (decision 2026-11-05, v0.30.0, CONCEPT.md §9): hotspot page fingerprints, configuration blocks
 * from the stored router state, the notice on dashboard, site page and router state, the scheduled check with its
 * notifications on the transition only, and the check after a version change.
 */
class RouterFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private const SETUP = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan-guest', 'subnet' => '198.51.100.0/24',
        'gateway' => '198.51.100.1', 'pool' => '198.51.100.10-198.51.100.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2',
        'wifi_modes' => ['capsman2'], 'html_directory' => 'hotspot'];

    private Site $site;

    private NasClient $nas;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://portal.example.net']);
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot-a.guest.example.net'], 'radius_address' => '192.0.2.10', 'setup' => self::SETUP]]);
        $this->nas = NasClient::find($this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '192.0.2.1', 'coa_port' => 3799])->id);
    }

    private function fetchPage(string $page, ?NasClient $nas = null): void
    {
        $nas ??= $this->nas;
        $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => $nas->nas_ip])
            ->withHeader('X-Portal-Token', $nas->certToken())->get("/router-login/{$nas->id}/{$page}.html")->assertOk();
    }

    private function fetchAll(): void
    {
        foreach (['login', 'status', 'logout', 'alogin'] as $page) {
            $this->fetchPage($page);
        }
    }

    private function withApi(): void
    {
        $this->nas->forceFill(['api_host' => '192.0.2.1', 'api_type' => 'rest', 'api_user' => 'portal-ro',
            'api_password' => 'Test pass 1', 'api_pin' => self::PIN])->save();
    }

    /** Menus of a state with the given rows; menus not listed are absent (their objects "unknown"). */
    private function state(array $menus = []): array
    {
        return ['menus' => array_map(fn ($rows) => ['rows' => $rows], $menus), 'host' => '192.0.2.1'];
    }

    private function check(?NasClient $nas = null): array
    {
        return app(RouterFreshness::class)->check(($nas ?? $this->nas)->fresh()->load('site'));
    }

    private function admin(): static
    {
        return $this->onZone('admin')->actingAs(User::factory()->admin()->create());
    }

    public function test_fetch_stores_fingerprint_and_time_per_page_and_a_changed_portal_url_outdates_them(): void
    {
        $this->assertSame(['login', 'status', 'logout', 'alogin'], $this->check()['pages'], 'never fetched');
        $this->fetchPage('status');
        $pages = $this->nas->fresh()->hotspot_pages;
        $this->assertSame(['status'], array_keys($pages));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pages['status']['hash']);
        $this->assertSame(RouterFreshness::currentHashes($this->site)['status'], $pages['status']['hash']);
        $this->assertNotNull($pages['status']['at']);
        $this->assertNotNull($this->nas->fresh()->login_page_fetched_at);
        $this->assertSame(['login', 'logout', 'alogin'], $this->check()['pages']);

        $this->fetchAll();
        $this->assertSame(4, count($this->nas->fresh()->hotspot_pages));
        $result = $this->check();
        $this->assertSame([], $result['pages']);
        $this->assertTrue($result['upToDate']);
        $this->assertSame(RouterFreshness::CONFIG_UNCHECKED, $result['config']);

        // Portal URL of the site changed: every page that carries it is outdated (alogin has no portal URL)
        $this->site->forceFill(['settings' => array_merge($this->site->settings, ['portal_url' => 'https://guest.example.net'])])->save();
        $this->assertSame(['login', 'status', 'logout'], $this->check()['pages']);
        $this->fetchAll();
        // Site slug changed
        $this->site->forceFill(['slug' => 'site-b'])->save();
        $this->assertSame(['login', 'status', 'logout'], $this->check()['pages']);
        $this->assertFalse($this->check()['upToDate']);
    }

    public function test_configuration_blocks_from_the_stored_state(): void
    {
        $k = MikrotikRos7Driver::key(...);
        $this->fetchAll();
        // No API access: not checked
        $this->assertSame(RouterFreshness::CONFIG_UNCHECKED, $this->check()['config']);

        $this->withApi();
        // API access without a state yet: pending, not outdated
        $result = $this->check();
        $this->assertSame([RouterFreshness::CONFIG_PENDING, false, false], [$result['config'], $result['outdated'], $result['upToDate']]);

        // Everything that was queried is present (menus not queried are unknown); no container installed
        $this->nas->storeRouterState($this->state(['/interface veth' => [], '/container mounts' => [],
            '/interface vlan' => [['name' => 'vlan-guest', 'interface' => 'bridge1', 'vlan-id' => '30', 'comment' => $k('net', 'vlan')]]]));
        $result = $this->check();
        $this->assertSame([RouterFreshness::CONFIG_OK, [], true], [$result['config'], $result['blocks'], $result['upToDate']]);

        // differs (net), legacy (net), missing (radius)
        $this->nas->storeRouterState($this->state(['/interface vlan' => [['name' => 'vlan-guest', 'interface' => 'bridge1', 'vlan-id' => '31', 'comment' => $k('net', 'vlan')]]]));
        $this->assertSame(['net'], $this->check()['blocks']);
        $this->nas->storeRouterState($this->state(['/ip pool' => [['name' => 'guest-pool', 'ranges' => '198.51.100.10-198.51.100.250', 'comment' => 'guest-portal']]]));
        $this->assertSame(['net'], $this->check()['blocks']);
        $this->nas->storeRouterState($this->state(['/radius' => [['address' => '192.0.2.99', 'service' => 'hotspot', 'comment' => 'foreign']]]));
        $result = $this->check();
        $this->assertSame([RouterFreshness::CONFIG_OUTDATED, ['radius'], true, false], [$result['config'], $result['blocks'], $result['outdated'], $result['upToDate']]);
        // An installed container counts
        $this->nas->storeRouterState($this->state(['/interface veth' => [['name' => 'veth-portal', 'comment' => $k('container', 'veth')]], '/container mounts' => []]));
        $this->assertSame(['container'], $this->check()['blocks']);

        // State older than the setup inputs: check pending, not outdated
        $this->travel(1)->minutes();
        $this->site->forceFill(['name' => 'Site A2'])->save();
        $result = $this->check();
        $this->assertSame([RouterFreshness::CONFIG_PENDING, [], false], [$result['config'], $result['blocks'], $result['outdated']]);

        // Unreachable after the second failed query in a row (the old state is kept)
        $this->nas->refresh()->storeRouterState(null, ['kind' => 'connect', 'detail' => 'refused']);
        $this->assertSame(RouterFreshness::CONFIG_PENDING, $this->check()['config']);
        $this->travel(1)->minutes();
        $this->nas->refresh()->storeRouterState(null, ['kind' => 'timeout', 'detail' => 'slow']);
        $result = $this->check();
        $this->assertSame(RouterFreshness::CONFIG_UNREACHABLE, $result['config']);
        $this->assertTrue($result['unreachable']);
        $this->assertSame(2, $this->nas->fresh()->router_state_failures);
        $this->assertEqualsWithDelta(now()->subMinute()->timestamp, $result['unreachableSince']->timestamp, 2);
        $this->assertNotNull($this->nas->fresh()->router_state);
        // A successful query resets the count
        $this->nas->refresh()->storeRouterState($this->state());
        $this->assertSame([0, null], [$this->nas->fresh()->router_state_failures, $this->nas->fresh()->router_state_failed_since]);
        $this->assertSame(RouterFreshness::CONFIG_OK, $this->check()['config']);
    }

    public function test_generic_driver_compares_only_pages_that_were_fetched(): void
    {
        $site = Site::create(['slug' => 'generic', 'name' => 'Generic', 'driver' => 'generic_radius']);
        $nas = NasClient::find($site->nasClients()->create(['name' => 'AP', 'nas_ip' => '192.0.2.5'])->id);
        $this->assertTrue($this->check($nas)['upToDate']);
        $this->fetchPage('login', $nas);
        $this->assertTrue($this->check($nas)['upToDate']);
        $site->forceFill(['settings' => ['portal_url' => 'https://guest.example.net']])->save();
        $this->assertSame(['login'], $this->check($nas)['pages']);
    }

    public function test_local_columns_are_not_replicated(): void
    {
        foreach (['hotspot_pages', 'router_state_failures', 'router_state_failed_since', 'freshness_notified', 'login_page_fetched_at'] as $column) {
            $this->assertContains($column, Federation::localColumns('nas_clients'));
        }
    }

    public function test_dashboard_site_page_and_router_state_show_the_notice_with_commands(): void
    {
        $k = MikrotikRos7Driver::key(...);
        $this->fetchPage('login');
        $this->withApi();
        $this->nas->storeRouterState($this->state(['/interface vlan' => [['name' => 'vlan-guest', 'interface' => 'bridge1', 'vlan-id' => '31', 'comment' => $k('net', 'vlan')]],
            '/radius' => []]));
        $ok = NasClient::find($this->site->nasClients()->create(['name' => 'Router B', 'nas_ip' => '192.0.2.2'])->id);
        foreach (['login', 'status', 'logout', 'alogin'] as $page) {
            $this->fetchPage($page, $ok);
        }

        $dashboard = $this->admin()->get('/admin')->assertOk()
            ->assertSee(__('portal.router_fresh.title'))
            ->assertSee('data-router-outdated="'.$this->nas->id.'"', false)
            ->assertDontSee('data-router-outdated="'.$ok->id.'"', false)
            ->assertSee(__('portal.router_fresh.pages', ['pages' => 'status.html, logout.html, alogin.html']))
            ->assertSee(__('portal.router_fresh.blocks', ['blocks' => 'RADIUS, '.__('portal.router_api.blocks.net')]))
            ->assertSee("/admin/sites/{$this->site->id}/setup#router-update-{$this->nas->id}", false)->getContent();
        // Compact: no command blocks on the dashboard
        $this->assertStringNotContainsString('/tool fetch', $dashboard);

        foreach (["/admin/sites/{$this->site->id}/edit", "/admin/sites/{$this->site->id}/setup"] as $url) {
            $page = $this->admin()->get($url)->assertOk()
                ->assertSee('id="router-update-'.$this->nas->id.'"', false)
                ->assertDontSee('id="router-update-'.$ok->id.'"', false)
                ->assertSee('data-outdated-pages="status,logout,alogin"', false)
                ->assertSee('data-outdated-blocks="radius,net"', false)
                // The fetch block, the router script link (radius) and the network blocks (net)
                ->assertSee(__('portal.router_fresh.pages_command', ['router' => 'Router A']))
                ->assertSee('data-outdated-command="router"', false)
                ->assertSee("/admin/sites/{$this->site->id}/routers/{$this->nas->id}/config#block-router", false)
                ->assertSee('data-outdated-command="vlan"', false)->assertSee('data-outdated-command="dhcp"', false)
                ->getContent();
            $notice = substr($page, strpos($page, 'data-router-update'), strpos($page, '</section>', strpos($page, 'data-router-update')) - strpos($page, 'data-router-update'));
            $this->assertStringContainsString("/router-login/{$this->nas->id}/status.html", $notice);
            $this->assertStringContainsString('/interface vlan add name=vlan-guest interface=bridge1 vlan-id=30', $notice);
        }

        // German, unreachable router
        $this->nas->storeRouterState(null, ['kind' => 'connect', 'detail' => 'refused']);
        $this->nas->storeRouterState(null, ['kind' => 'connect', 'detail' => 'refused']);
        $this->admin()->withSession(['locale' => 'de'])->get('/admin')->assertOk()->assertSee('Router aktualisieren')
            ->assertSee('Nicht erreichbar seit');

        // Managers see no router data; nothing at all when every router is up to date
        $this->onZone('admin')->actingAs(User::factory()->create())->get('/admin')->assertOk()->assertDontSee('data-router-update', false);
        $this->nas->forceFill(['api_host' => null])->save();
        foreach (['login', 'status', 'logout', 'alogin'] as $page) {
            $this->fetchPage($page);
        }
        $this->admin()->get('/admin')->assertOk()->assertDontSee('data-router-update', false);
        $this->admin()->get("/admin/sites/{$this->site->id}/setup")->assertOk()->assertDontSee('data-router-update', false);
    }

    /** Fake router REST API (answers from $menus, or $fail as HTTP status) and notification endpoints. */
    private function fakeHttp(array $menus, ?int &$fail, array &$queried): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($menus, &$fail, &$queried) {
            if (preg_match('#^https://(192\.0\.2\.\d+)(?::443)?/rest/(.+)/print$#', $request->url(), $m)) {
                $queried[] = $m[1];
                if ($fail !== null) {
                    return Http::response(['error' => $fail, 'message' => 'Unauthorized'], $fail);
                }
                $menu = '/'.str_replace('/', ' ', $m[2]);
                if (! array_key_exists($menu, $menus)) {
                    return Http::response(['error' => 400, 'message' => 'Bad Request', 'detail' => 'no such command prefix'], 400);
                }

                return Http::response(array_map(fn ($row) => array_intersect_key($row + ['.id' => '*1'], array_flip($request->data()['.proplist'])), $menus[$menu]));
            }
            if (str_starts_with($request->url(), 'https://api.telegram.org/')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => -100]]]);
            }

            return Http::response(['id' => 1]);
        });
    }

    private function channels(): void
    {
        Channel::create(['kind' => 'notification', 'driver' => 'gotify', 'name' => 'Gotify', 'enabled' => true,
            'config' => ['url' => 'https://gotify.example.net', 'app_token' => 'app-token']]);
        Channel::create(['kind' => 'notification', 'driver' => 'webhook', 'name' => 'Hook', 'enabled' => true,
            'config' => ['url' => 'https://hooks.example.net/in', 'secret' => 'hook-secret']]);
        Channel::create(['kind' => 'notification', 'driver' => 'telegram', 'name' => 'Group', 'enabled' => true,
            'config' => ['bot_token' => 'test-token', 'chat_id' => '-100']]);
    }

    private function messages(): array
    {
        return array_values(array_filter(Http::recorded()->all(), fn ($pair) => ! str_contains($pair[0]->url(), '/rest/')));
    }

    public function test_command_queries_sequentially_and_notifies_only_on_transitions(): void
    {
        $k = MikrotikRos7Driver::key(...);
        $this->channels();
        $this->fetchAll();
        $this->withApi();
        $second = NasClient::find($this->site->nasClients()->create(['name' => 'Router B', 'nas_ip' => '192.0.2.2'])->id);
        $second->forceFill(['api_host' => '192.0.2.2', 'api_type' => 'rest', 'api_user' => 'portal-ro', 'api_password' => 'Test pass 1', 'api_pin' => self::PIN])->save();
        foreach (['login', 'status', 'logout', 'alogin'] as $page) {
            $this->fetchPage($page, $second);
        }
        $noApi = NasClient::find($this->site->nasClients()->create(['name' => 'Router C', 'nas_ip' => '192.0.2.3'])->id);
        $fail = null;
        $queried = [];
        $menus = ['/interface vlan' => [['name' => 'vlan-guest', 'interface' => 'bridge1', 'vlan-id' => '30', 'comment' => $k('net', 'vlan')]]];
        $this->fakeHttp($menus, $fail, $queried);

        // Router C never fetched its pages: outdated, notified once; A and B up to date
        $this->artisan('portal:router-state-check')->assertSuccessful()
            ->expectsOutputToContain('Site A / Router C: outdated pages: login.html, status.html, logout.html, alogin.html (notified)')
            ->expectsOutputToContain('Site A / Router A: up to date');
        // One router after the other: all menus of A, then all of B; C is not queried
        $this->assertSame(['192.0.2.1', '192.0.2.2'], array_values(array_unique($queried)));
        $this->assertSame(count($queried) / 2, count(array_filter($queried, fn ($h) => $h === '192.0.2.1')));
        $this->assertSame(array_fill(0, count($queried) / 2, '192.0.2.1'), array_slice($queried, 0, count($queried) / 2));
        $this->assertNotNull($this->nas->fresh()->router_state_at);
        $sent = $this->messages();
        $this->assertCount(3, $sent);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://gotify.example.net/message' && $r['title'] === __('portal.router_fresh.notify.title', [], 'en')
            && str_contains($r['message'], 'Router C') && str_contains($r['message'], 'login.html')
            && $r['extras']['client::notification']['click']['url'] === 'https://portal.example.net:8443/admin/sites/'.$this->site->id.'/setup#router-update-'.$noApi->id);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hooks.example.net/in' && $r['event'] === 'router_outdated'
            && $r['router']['name'] === 'Router C' && $r['site']['slug'] === 'site-a' && $r['pages'] === ['login', 'status', 'logout', 'alogin']
            && $r['blocks'] === [] && $r['unreachable'] === false && $r->hasHeader('X-Signature'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Router C'));
        $this->assertArrayHasKey('outdated', $noApi->fresh()->freshness_notified);

        // Next run: still outdated, no second message
        $before = count($this->messages());
        $this->artisan('portal:router-state-check')->assertSuccessful();
        $this->assertCount($before, $this->messages());

        // Fetched again: up to date (the fetch removes the mark), outdated again later: notified again
        foreach (['login', 'status', 'logout', 'alogin'] as $page) {
            $this->fetchPage($page, $noApi);
        }
        $this->assertNull($noApi->fresh()->freshness_notified);
        $this->site->forceFill(['settings' => array_merge($this->site->settings, ['portal_url' => 'https://guest.example.net'])])->save();
        $this->travel(1)->minutes();
        $this->artisan('portal:router-state-check')->assertSuccessful();
        // All three routers have outdated pages now: one message per router and channel
        $this->assertCount($before + 9, $this->messages());
        foreach ([$this->nas, $second, $noApi] as $nas) {
            foreach (['login', 'status', 'logout', 'alogin'] as $page) {
                $this->fetchPage($page, $nas);
            }
        }
        $this->artisan('portal:router-state-check')->assertSuccessful();
        foreach ([$this->nas, $second, $noApi] as $nas) {
            $this->assertNull($nas->fresh()->freshness_notified);
        }

        // Unreachable: the first failure is quiet, the second notifies once, the third is quiet again
        $before = count($this->messages());
        $fail = 401;
        $this->artisan('portal:router-state-check')->assertSuccessful();
        $this->assertCount($before, $this->messages());
        $this->assertSame(1, $this->nas->fresh()->router_state_failures);
        $this->artisan('portal:router-state-check')->assertSuccessful()->expectsOutputToContain('Site A / Router A: unreachable (notified)');
        $this->assertCount($before + 6, $this->messages());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hooks.example.net/in' && $r['router']['name'] === 'Router A'
            && $r['unreachable'] === true && $r['unreachable_since'] !== null);
        $this->artisan('portal:router-state-check')->assertSuccessful();
        $this->assertCount($before + 6, $this->messages());
        // Reachable again: the mark is gone
        $fail = null;
        $this->artisan('portal:router-state-check')->assertSuccessful();
        $this->assertNull($this->nas->fresh()->freshness_notified);
        $this->assertCount($before + 6, $this->messages());
    }

    public function test_a_configuration_change_notifies_with_the_blocks(): void
    {
        $k = MikrotikRos7Driver::key(...);
        $this->channels();
        $this->fetchAll();
        $this->withApi();
        $fail = null;
        $queried = [];
        $this->fakeHttp(['/interface vlan' => [['name' => 'vlan-guest', 'interface' => 'bridge1', 'vlan-id' => '29', 'comment' => $k('net', 'vlan')]]], $fail, $queried);
        $this->artisan('portal:router-state-check')->assertSuccessful()->expectsOutputToContain('Site A / Router A: outdated blocks: net (notified)');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://hooks.example.net/in' && $r['blocks'] === ['net'] && $r['pages'] === []);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://gotify.example.net/message'
            && str_contains($r['message'], __('portal.router_fresh.notify.blocks', ['blocks' => __('portal.router_api.blocks.net', [], 'en')], 'en')));
    }

    public function test_routers_of_sites_served_elsewhere_are_neither_checked_nor_notified(): void
    {
        $this->channels();
        $other = Instance::create(['name' => 'Other']);
        $this->site->forceFill(['home_instance_id' => $other->id])->save();
        $this->withApi();
        $fail = null;
        $queried = [];
        $this->fakeHttp([], $fail, $queried);
        $this->artisan('portal:router-state-check')->assertSuccessful();
        $this->assertSame([], $queried);
        $this->assertSame([], $this->messages());
        $this->assertNull($this->nas->fresh()->freshness_notified);
        $this->admin()->get('/admin')->assertOk()->assertDontSee('data-router-update', false);
    }

    public function test_version_change_queues_one_check(): void
    {
        Queue::fake();
        config(['portal.version' => '0.30.0']);
        $this->artisan('portal:init')->assertSuccessful()->expectsOutputToContain('Router check queued');
        Queue::assertPushedOn('router-state', CheckRouters::class);
        $this->assertSame('0.30.0', LocalSetting::get(RouterCheck::VERSION_KEY));
        // Same version: no further check
        $this->artisan('portal:init')->assertSuccessful()->doesntExpectOutputToContain('Router check queued');
        Queue::assertPushed(CheckRouters::class, 1);
        config(['portal.version' => '0.30.1']);
        $this->assertTrue(RouterCheck::afterVersionChange());
        Queue::assertPushed(CheckRouters::class, 2);

        // The job runs the check
        $this->assertNull($this->nas->fresh()->freshness_notified);
        (new CheckRouters)->handle(app(RouterCheck::class));
        $this->assertArrayHasKey('outdated', $this->nas->fresh()->freshness_notified);
    }

    public function test_schedule_runs_the_check_twice_a_day(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'portal:router-state-check'));
        $this->assertNotNull($event);
        $this->assertSame('17 6,18 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('ok.html', RouterNotifier::files(['ok']));
    }
}
