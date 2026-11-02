<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\WizardController;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use App\Nas\MikrotikRos7Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guided setup wizard (decision 2026-11-02): additional view of the setup assistant, one step per block,
 * saves through the setup actions, check through the per-router state query, scoped to the step.
 */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private const NETWORK = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24',
        'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_modes' => ['capsman2']];

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

    protected function url(?int $step = null): string
    {
        return "/admin/sites/{$this->site->id}/wizard".($step !== null ? "?step={$step}" : '');
    }

    protected function step(string $key): int
    {
        return array_search($key, array_keys(WizardController::STEPS), true) + 1;
    }

    protected function configureApi(): void
    {
        $this->admin()->put("/admin/sites/{$this->site->id}/routers/{$this->nas->id}/api", ['api_host' => '10.0.1.1',
            'api_type' => 'rest', 'api_user' => 'portal-ro', 'api_password' => 'Api secret 1',
            'api_pin' => 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='])->assertSessionHasNoErrors();
        $this->nas->refresh();
    }

    /** Fake REST router answering every print from $menus (others: no such command). */
    protected function fakeRest(array $menus): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($menus) {
            if (! preg_match('#^https://10\.0\.1\.1(?::443)?/rest/(.+)/print$#', $request->url(), $m)) {
                return Http::response(['error' => 400, 'detail' => 'unexpected'], 400);
            }
            $menu = '/'.str_replace('/', ' ', $m[1]);
            if (! array_key_exists($menu, $menus)) {
                return Http::response(['error' => 400, 'message' => 'Bad Request', 'detail' => 'no such command prefix'], 400);
            }

            return Http::response(array_map(fn ($row) => array_intersect_key($row + ['.id' => '*1'], array_flip($request->data()['.proplist'])), $menus[$menu]));
        });
    }

    /** Router with the guest network objects of NETWORK, no RADIUS client. */
    protected function routerMenus(): array
    {
        $k = MikrotikRos7Driver::key(...);

        return [
            '/system resource' => [['version' => '7.24.5', 'board-name' => 'CHR']],
            '/interface bridge' => [['name' => 'bridge1', 'comment' => 'defconf']],
            '/interface vlan' => [['name' => 'vlan-guest', 'interface' => 'bridge1', 'vlan-id' => '30', 'comment' => $k('net', 'vlan')]],
            '/ip address' => [['address' => '10.30.0.1/24', 'interface' => 'vlan-guest', 'comment' => $k('net', 'gateway')]],
            '/ip pool' => [['name' => 'guest-pool', 'ranges' => '10.30.0.10-10.30.0.250', 'comment' => $k('net', 'pool')]],
            '/ip dhcp-server' => [['name' => 'guest-dhcp', 'interface' => 'vlan-guest', 'address-pool' => 'guest-pool', 'lease-time' => '30m', 'disabled' => 'false', 'comment' => $k('net', 'dhcp-server')]],
            '/ip dhcp-server network' => [['address' => '10.30.0.0/24', 'gateway' => '10.30.0.1', 'dns-server' => '10.30.0.1', 'comment' => $k('net', 'dhcp-network')]],
            '/interface bridge vlan' => [],
            '/radius' => [],
        ];
    }

    public function test_admin_only_and_every_step_renders(): void
    {
        $this->onZone('admin')->actingAs(User::factory()->create())->get($this->url())->assertForbidden();
        $this->onZone('guest')->get($this->url())->assertNotFound();
        $this->assertNotSame(200, $this->onZone('admin')->get($this->url())->getStatusCode());

        foreach (array_keys(WizardController::STEPS) as $i => $key) {
            $page = $this->admin()->get($this->url($i + 1))->assertOk()->getContent();
            $this->assertStringContainsString('data-wizard-step="'.$key.'"', $page);
            $this->assertStringContainsString(e(__('portal.wizard.steps.'.$key.'.title')), $page);
            $this->assertSame(count(WizardController::STEPS), substr_count($page, 'data-wizard-nav-step='));
        }
        // Invalid step numbers fall back to the first step
        foreach (['0', '99', 'x', '1;'] as $bad) {
            $this->assertStringContainsString('data-wizard-step="access"', $this->admin()->get("/admin/sites/{$this->site->id}/wizard?step=".urlencode($bad))->getContent());
        }
        // Linked from the setup page and the site page; uninstall only as a link to the setup page
        $this->assertStringContainsString('data-wizard-link', $this->admin()->get("/admin/sites/{$this->site->id}/setup")->getContent());
        $this->assertStringContainsString('data-wizard-link', $this->admin()->get("/admin/sites/{$this->site->id}/edit")->getContent());
        $page = $this->admin()->get($this->url())->getContent();
        $this->assertStringContainsString('/setup#step-uninstall', $page);
        $this->assertStringNotContainsString('/system script remove', $page);
    }

    public function test_step_order_and_router_script_step_contents(): void
    {
        $this->assertSame(['access', 'container', 'network', 'wifi', 'firewall', 'hotspot', 'router', 'login', 'status'], array_keys(WizardController::STEPS));
        $this->assertSame(['radius', 'walled', 'cert'], array_keys(WizardController::STEPS['router']['scope']));

        // Router script step absorbs the certificate checklist and the RADIUS diagnostics, without the old "run again" hint
        $page = $this->admin()->get($this->url(7))->assertOk()->getContent();
        $this->assertStringContainsString('data-wizard-step="router"', $page);
        $this->assertStringContainsString('data-router-script="'.$this->nas->id.'"', $page);
        $this->assertStringContainsString(e(__('portal.setup.diagnostics.title')), $page);
        $this->assertStringContainsString(e(__('portal.wizard.radius_address')), $page);
        $this->assertStringContainsString('id="cert-checklist"', $page);
        $this->assertStringNotContainsString('run it again', strtolower(__('portal.wizard.router_script_secret')));
        foreach (['de', 'en', 'fr', 'it'] as $lang) {
            $wizard = trans('portal.wizard', [], $lang);
            $this->assertArrayNotHasKey('certificate', $wizard['steps']);
            $this->assertArrayNotHasKey('radius', $wizard['steps']);
            foreach (array_keys(WizardController::STEPS) as $key) {
                $this->assertArrayHasKey($key, $wizard['steps'], "{$lang} {$key}");
            }
            $this->assertArrayHasKey('unused', $wizard['progress']);
            $this->assertNotSame('portal.wizard.container_unused', __('portal.wizard.container_unused', [], $lang));
        }
    }

    public function test_container_step_offers_not_used_option_stored_by_step_name(): void
    {
        $page = $this->admin()->get($this->url($this->step('container')))->assertOk()->getContent();
        $this->assertStringContainsString('data-wizard-container-unused', $page);
        $this->assertStringContainsString(e(__('portal.wizard.container_unused')), $page);
        $this->assertStringContainsString('data-wizard-form="container"', $page);
        // Progress keys are step-name based ("step:container"), not positions
        $this->assertStringContainsString("'step:' + step", $page);
        $this->assertStringNotContainsString('progress[step]', $page);
    }

    public function test_container_step_hints_at_a_missing_package_or_device_mode(): void
    {
        $driver = $this->site->driver();
        $missing = ['error' => 'no such command prefix'];
        $this->assertSame('package', $driver->containerProblem(['menus' => ['/container mounts' => $missing, '/container envs' => $missing]]));
        $this->assertSame('device_mode', $driver->containerProblem(['menus' => ['/container mounts' => ['rows' => []],
            '/container envs' => ['rows' => []], '/system device-mode' => ['rows' => [['container' => 'no']]]]]));
        $this->assertNull($driver->containerProblem(['menus' => ['/container mounts' => ['rows' => []], '/container envs' => ['rows' => []],
            '/system device-mode' => ['rows' => [['container' => 'yes']]]]]));
        // Unknown (no state of these menus, or a device without the device-mode menu): no hint
        $this->assertNull($driver->containerProblem(['menus' => ['/system device-mode' => $missing]]));
        $this->assertArrayHasKey('/system device-mode', $driver->stateMenus($this->site));

        // No hint without a stored state
        $this->configureApi();
        foreach ([$this->url($this->step('container')), "/admin/sites/{$this->site->id}/setup"] as $url) {
            $this->assertStringNotContainsString('data-container-problem', $this->admin()->get($url)->assertOk()->getContent());
        }
        // Stored state without the container package: notice in the wizard step and on the setup page (only a hint,
        // the step stays selectable)
        $this->nas->forceFill(['router_state' => ['host' => '10.0.1.1', 'menus' => ['/container mounts' => $missing, '/container envs' => $missing]],
            'router_state_at' => now()])->save();
        foreach ([$this->url($this->step('container')), "/admin/sites/{$this->site->id}/setup"] as $url) {
            $page = $this->admin()->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-container-problem="package"', $page);
            $this->assertStringContainsString(e(__('portal.setup.container.problem.package', ['router' => 'Router A'])), $page);
            $this->assertStringContainsString('name="veth_address"', $page);
        }
        $this->nas->forceFill(['router_state' => ['host' => '10.0.1.1', 'menus' => ['/container mounts' => ['rows' => []],
            '/container envs' => ['rows' => []], '/system device-mode' => ['rows' => [['container' => 'false']]]]]])->save();
        $this->assertStringContainsString('data-container-problem="device_mode"', $this->admin()->get($this->url($this->step('container')))->getContent());
    }

    public function test_steps_show_their_blocks_and_save_back_to_the_wizard(): void
    {
        $network = $this->step('network');
        $this->admin()->from($this->url($network))->post("/admin/sites/{$this->site->id}/setup", self::NETWORK + ['wizard_step' => $network])
            ->assertRedirect($this->url($network).'#wizard-step')->assertSessionHasNoErrors();
        $this->assertSame('vlan-guest', $this->site->fresh()->setting('setup')['interface']);

        $page = $this->admin()->get($this->url($network))->assertOk()->getContent();
        $this->assertStringContainsString('/interface vlan add name=vlan-guest interface=bridge1 vlan-id=30', $page);
        $this->assertStringContainsString('data-wizard-form="network"', $page);
        $this->assertStringNotContainsString('guest-portal:fw:in-dhcp', $page);

        // Wi-Fi step: posts the network values hidden, keeps the stored Wi-Fi password when empty
        $wifi = $this->step('wifi');
        $page = $this->admin()->get($this->url($wifi))->getContent();
        $this->assertStringContainsString('<input type="hidden" name="vlan_id" value="30">', $page);
        $this->assertStringContainsString('data-wifi-modes', $page);
        $this->assertStringContainsString('/interface wifi datapath', $page);
        $this->admin()->from($this->url($wifi))->post("/admin/sites/{$this->site->id}/setup", array_merge(self::NETWORK, ['ssid' => 'Guest-2', 'wizard_step' => $wifi]))
            ->assertRedirect($this->url($wifi).'#wizard-step');
        $this->assertSame('Guest-2', $this->site->fresh()->setting('setup')['ssid']);

        // Firewall step: firewall and option 114 only
        $page = $this->admin()->get($this->url($this->step('firewall')))->getContent();
        $this->assertStringContainsString('guest-portal:fw:in-dhcp', $page);
        $this->assertStringContainsString('dhcp-option=captive-portal', $page);
        $this->assertStringNotContainsString('/interface vlan add', $page);

        // Container step saves the container inputs back to the wizard
        $container = $this->step('container');
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/container", ['veth_address' => '172.17.0.2/24', 'veth_gateway' => '172.17.0.1',
            'bridge' => 'containers', 'disk' => 'usb1', 'image' => 'ghcr.io/owner/repo:latest', 'wizard_step' => $container])
            ->assertRedirect($this->url($container).'#wizard-step');
        $this->assertStringContainsString('remote-image=ghcr.io/owner/repo:latest', $this->admin()->get($this->url($container))->getContent());

        // Without wizard_step (setup page) and with an invalid one: back to the setup page as before
        $this->admin()->post("/admin/sites/{$this->site->id}/setup", self::NETWORK)
            ->assertRedirect("/admin/sites/{$this->site->id}/setup#step-network");
        $this->admin()->post("/admin/sites/{$this->site->id}/setup", self::NETWORK + ['wizard_step' => '99'])
            ->assertRedirect("/admin/sites/{$this->site->id}/setup#step-network");

        // login.html step: fetch command per router
        $this->assertStringContainsString("/router-login/{$this->nas->id}/login.html", $this->admin()->get($this->url($this->step('login')))->getContent());
    }

    public function test_check_queries_the_router_and_shows_only_the_blocks_of_the_step(): void
    {
        $this->admin()->post("/admin/sites/{$this->site->id}/setup", self::NETWORK)->assertSessionHasNoErrors();
        $this->configureApi();
        $this->fakeRest($this->routerMenus());
        $network = $this->step('network');

        // Before the query: Next disabled, Skip offered
        $page = $this->admin()->get($this->url($network))->getContent();
        $this->assertMatchesRegularExpression('/data-wizard-next x-show="canNext"[^>]*style="display: none"/', $page);
        $this->assertStringContainsString('data-wizard-skip', $page);

        $this->admin()->post("/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/state", ['wizard_step' => $network])
            ->assertRedirect($this->url($network).'#wizard-step');
        $this->assertNotNull($this->nas->fresh()->router_state);

        $page = $this->admin()->get($this->url($network))->getContent();
        preg_match_all('/data-state-block="([a-z]+)"/', $page, $blocks);
        $this->assertSame(['net', 'net'], $blocks[1]);
        preg_match_all('/data-state-item="([^"]+)" data-status="([a-z]+)"/', $page, $items);
        $status = array_combine($items[1], $items[2]);
        $this->assertSame(['guest-portal:net:vlan', 'guest-portal:net:gateway', 'guest-portal:net:pool', 'guest-portal:net:dhcp-server',
            'guest-portal:net:dhcp-network', 'guest-portal:net:bridge-vlan'], array_keys($status));
        // The optional bridge VLAN entry is shown but does not block Next
        $this->assertSame('missing', $status['guest-portal:net:bridge-vlan']);
        $this->assertStringContainsString('data-wizard-router-ok="yes"', $page);
        $this->assertDoesNotMatchRegularExpression('/data-wizard-next x-show="canNext"[^>]*style="display: none"/', $page);
        $this->assertStringContainsString('data-step-status="network">'.e(__('portal.wizard.progress.verified')).'<', $page);

        // Router script step: radius, walled garden and certificate blocks only; RADIUS client missing here: Next disabled
        $page = $this->admin()->get($this->url($this->step('router')))->getContent();
        preg_match_all('/data-state-block="([a-z]+)"/', $page, $blocks);
        $this->assertEqualsCanonicalizing(['radius', 'walled', 'cert'], array_unique($blocks[1]));
        $this->assertStringContainsString('data-state-item="guest-portal:radius:server" data-status="missing"', $page);
        $this->assertStringContainsString('data-wizard-router-ok="no"', $page);
        $this->assertMatchesRegularExpression('/data-wizard-next x-show="canNext"[^>]*style="display: none"/', $page);

        // Stale after a change of the inputs: no longer verified
        $this->travel(1)->minutes();
        $this->admin()->post("/admin/sites/{$this->site->id}/setup", array_merge(self::NETWORK, ['ssid' => 'Guest-3']));
        $this->assertMatchesRegularExpression('/data-wizard-next x-show="canNext"[^>]*style="display: none"/', $this->admin()->get($this->url($network))->getContent());
    }

    public function test_routers_without_api_access_are_confirmed_by_hand(): void
    {
        $this->admin()->post("/admin/sites/{$this->site->id}/setup", self::NETWORK)->assertSessionHasNoErrors();
        $page = $this->admin()->get($this->url($this->step('network')))->getContent();
        $this->assertStringContainsString('data-wizard-manual="'.$this->nas->id.'"', $page);
        $this->assertStringContainsString('\\u0022manual\\u0022:[\\u0022'.$this->nas->id.'\\u0022]', $page);
        $this->assertStringContainsString('\\u0022apiOk\\u0022:true', $page);
        // No router query form for a router without API access
        $this->assertStringNotContainsString('data-wizard-query=', $page);
    }

    public function test_no_password_or_secret_is_rendered(): void
    {
        $wifi = 'Wifi "pass" $1?';
        $this->admin()->post("/admin/sites/{$this->site->id}/setup", self::NETWORK + ['wifi_password' => $wifi])->assertSessionHasNoErrors();
        $this->configureApi();
        $secret = (string) $this->nas->fresh()->radius_secret;
        $this->assertNotSame('', $secret);

        foreach (array_keys(WizardController::STEPS) as $i => $key) {
            $page = $this->admin()->get($this->url($i + 1))->assertOk()->getContent();
            foreach (['Api secret 1', $wifi, addcslashes($wifi, '\\"$?'), e(addcslashes($wifi, '\\"$?')), $secret] as $value) {
                $this->assertStringNotContainsString($value, $page, "step {$key}");
            }
        }
        // The Wi-Fi block keeps the placeholder with the guard; passwords only through "Insert passwords"
        $page = $this->admin()->get($this->url($this->step('wifi')))->getContent();
        $this->assertStringContainsString('passphrase=&quot;&lt;WIFI_PASSWORD&gt;&quot;', $page);
        $this->assertStringContainsString('data-insert-passwords', $page);
        $page = $this->admin()->get($this->url($this->step('access')))->getContent();
        $this->assertStringContainsString('&lt;API_PASSWORD&gt;', $page);
        // The router script (RADIUS secret, token) is only linked
        $this->assertStringContainsString("/routers/{$this->nas->id}/config", $this->admin()->get($this->url($this->step('router')))->getContent());
    }
}
