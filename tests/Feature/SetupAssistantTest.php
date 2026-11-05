<?php

namespace Tests\Feature;

use App\Enums\ExpiryMode;
use App\Models\NasClient;
use App\Models\RadiusAccounting;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Models\User;
use App\Services\GrantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setup assistant (CONCEPT.md §9): generated commands, login.html fetch route, router status.
 */
class SetupAssistantTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private NasClient $nas;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://portal.example.com']);
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot-a.guest.example.com'], 'radius_address' => '10.0.0.10']]);
        $this->nas = NasClient::find($this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.1.1', 'coa_port' => 3799])->id);
    }

    public function test_page_renders_with_saved_values_and_fills_router_script(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->get("/admin/sites/{$this->site->id}/setup")->assertForbidden();
        $this->onZone('guest')->get("/admin/sites/{$this->site->id}/setup")->assertNotFound();

        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup", [
            'vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24',
            'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman1',
        ])->assertRedirect();
        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup/container", [
            'veth_address' => '172.17.0.2/24', 'veth_gateway' => '172.17.0.1', 'bridge' => 'containers',
            'disk' => 'usb1', 'image' => 'ghcr.io/owner/repo:latest',
        ])->assertRedirect();

        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee('/interface vlan add name=vlan-guest interface=bridge1 vlan-id=30', false)
            ->assertSee('/caps-man datapath add name=dp-guest bridge=bridge1 vlan-id=30', false)
            ->assertSee('ssid="Guest-WiFi"')
            ->assertSee('/ip address add interface=vlan-guest address=10.30.0.1/24', false)
            ->assertSee('/ip pool add name=guest-pool ranges=10.30.0.10-10.30.0.250', false)
            ->assertSee('value="\'https://portal.example.com/captive/site-a\'"')
            ->assertDontSee('add name=hotspot-a.guest.example.com', false)
            ->assertSee('/ip/address add interface=containers address=172.17.0.1/24', false)
            ->assertSee('mountlists=portal-data envlist=portal', false)
            ->assertSee('remote-image=ghcr.io/owner/repo:latest', false)
            ->assertSee("/router-login/{$this->nas->id}/login.html", false)
            ->assertSee('X-Portal-Token: '.$this->nas->cert_token, false)
            ->assertSee('/radius monitor [find where comment="guest-portal:radius:server"] once')
            ->assertSee('key=APP_URL value="https://portal.example.com"')
            ->assertSee('/caps-man provisioning find where master-configuration="<MASTER_CFG>"')
            ->assertSee('slave-configurations=($gpList . "cfg-guest")')
            ->assertDontSee('<EXISTING_SLAVES>');

        $script = $this->site->fresh()->driver()->configScript($this->site->fresh(), $this->nas);
        $this->assertStringContainsString('hotspot-address=10.30.0.1', $script);
        $this->assertStringContainsString('interface=vlan-guest address-pool=guest-pool profile=guest-portal', $script);
    }

    public function test_values_are_validated_and_sanitized_against_script_injection(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup", [
            'vlan_id' => 30, 'bridge' => 'bridge1; /system reset-configuration', 'interface' => 'vlan-guest',
            'subnet' => '10.30.0.0/24', 'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250',
            'ssid' => 'Guest"; /system reset-configuration; "', 'wifi_mode' => 'capsman2',
        ])->assertSessionHasErrors(['bridge', 'ssid']);
        $this->assertNull($this->site->fresh()->setting('setup'));

        // Values bypassing validation (e.g. replicated from elsewhere) are stripped on output.
        $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => [
            'vlan_id' => 'x30 disabled=yes', 'bridge' => 'br1 [/system reset-configuration]', 'interface' => 'vlan-guest"$x',
            'subnet' => '10.30.0.0/24', 'gateway' => '10.30.0.1 ;:put', 'pool' => '10.30.0.10-10.30.0.250',
            'ssid' => 'Guest"; :execute {/system reset-configuration}; "', 'wifi_mode' => 'local',
        ]])]);
        $commands = $this->site->driver()->setupCommands($this->site, ['bridge' => 'c"; /x', 'disk' => 'usb1 $(x)']);
        $all = implode("\n", array_merge(...array_values(array_map('array_values', $commands))));
        $this->assertStringNotContainsString('reset-configuration]', $all);
        $this->assertStringNotContainsString('{/system', $all);
        $this->assertStringNotContainsString('$x', $all);
        $this->assertStringNotContainsString('$(x)', $all);
        $this->assertStringContainsString('ssid="Guest :execute /system reset-configuration"', $all);
        $this->assertStringContainsString('<GUEST_VLAN_ID>', $all);
        $this->assertStringContainsString('interface=vlan-guestx', $all);
        $this->assertStringContainsString('bridge=br1/systemreset-configuration', $all);

        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertDontSee('"; :execute', false);
    }

    public function test_firewall_uses_coa_ports_of_routers_and_radius_address_as_source(): void
    {
        $this->site->update(['settings' => array_merge($this->site->settings, ['portal_address' => '10.0.0.20'])]);
        $this->site->nasClients()->create(['name' => 'Router B', 'nas_ip' => '10.0.2.1', 'coa_port' => 1700]);
        $firewall = $this->site->fresh()->driver()->setupCommands($this->site->fresh(), [])['network']['firewall'];

        $this->assertStringContainsString('add list=PORTAL address=10.0.0.20', $firewall);
        $this->assertStringContainsString('add list=PORTAL_RADIUS address=10.0.0.10', $firewall);
        $this->assertStringContainsString('src-address-list=PORTAL_RADIUS dst-port=1700,3799 comment="guest-portal:fw:in-coa"', $firewall);
    }

    public function test_firewall_rules_are_moved_before_foreign_rules_of_input_and_forward(): void
    {
        $firewall = $this->site->driver()->setupCommands($this->site, [])['network']['firewall'];

        // Every filter rule carries its own key; the block replaces only those (and legacy rules) on re-run.
        preg_match_all('/^add chain=(input|forward) .*comment="([^"]*)"$/m', $firewall, $rules);
        $this->assertCount(12, $rules[2]);
        $this->assertCount(12, array_unique($rules[2]));
        $keys = array_keys($this->site->driver()->managedObjects($this->site)['fw']);
        foreach ($rules[2] as $comment) {
            $this->assertMatchesRegularExpression('/^guest-portal:fw:[a-z0-9-]+$/', $comment);
            $this->assertContains($comment, $keys);
        }
        $exact = '('.implode(' || ', array_map(fn ($k) => 'comment="'.$k.'"', $rules[2])).')';
        $this->assertStringContainsString('/ip firewall filter remove [find where ('.substr($exact, 0, -1).' || comment="guest-portal" || comment~"^guest-portal: ") && !dynamic)]', $firewall);

        // Moved rule by rule before the first static foreign rule: keeps the own order.
        $this->assertStringContainsString(':foreach fwChain in={"input";"forward"} do={', $firewall);
        $this->assertStringContainsString('[/ip firewall filter find where chain=$fwChain && dynamic=no && !'.$exact.'] 0]', $firewall);
        $this->assertStringContainsString('find where chain=$fwChain && dynamic=no && '.$exact.'] do={', $firewall);
        $this->assertStringContainsString('/ip firewall filter move $fwRule destination=$fwFirst', $firewall);
        $this->assertLessThan(strpos($firewall, ':foreach fwChain'), strrpos($firewall, "\nadd chain="));

        // Stateful accepts only for guest traffic (FastTrack of other traffic unchanged), FastTrack hint.
        $this->assertStringNotContainsString('connection-state=established,related comment=', $firewall);
        $this->assertStringContainsString('established,related in-interface-list=GUEST comment="guest-portal:fw:fwd-from-guests"', $firewall);
        $this->assertStringContainsString('fasttrack-connection', $firewall);
        $this->assertStringContainsString('/ip firewall filter print where comment~"guest-portal"', $firewall);
    }

    public function test_hotspot_hosts_reject_spaces_and_script_characters(): void
    {
        $admin = User::factory()->admin()->create();
        $base = ['name' => 'Site A', 'slug' => 'site-a', 'driver' => 'mikrotik_ros7'];
        foreach (['hotspot a.example.com', "a.example.com\nb.example.com", 'a.example.com;b', 'a.example.com"x'] as $bad) {
            $this->onZone('admin')->actingAs($admin)->put("/admin/sites/{$this->site->id}", $base + ['hotspot_hosts' => $bad])
                ->assertSessionHasErrors('hotspot_hosts');
        }
        $this->onZone('admin')->actingAs($admin)->put("/admin/sites/{$this->site->id}", $base + ['hotspot_hosts' => 'a.example.com, b.example.com'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['a.example.com', 'b.example.com'], $this->site->fresh()->setting('hotspot_hosts'));

        // Stored values bypassing validation are reduced to host name characters.
        $this->site->update(['settings' => array_merge($this->site->settings, ['hotspot_hosts' => ['a.example.com use-radius=no']])]);
        $script = $this->site->driver()->configScript($this->site, $this->nas);
        $this->assertStringContainsString('dns-name=a.example.comuse-radiusno ', $script);
        $this->assertStringNotContainsString('use-radius=no', $script);
    }

    public function test_network_inputs_must_be_valid_ipv4_inside_the_subnet(): void
    {
        $admin = User::factory()->admin()->create();
        $valid = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24',
            'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2'];
        $post = fn (array $data) => $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup", $data);

        $post(['subnet' => '10.30.0.999/24'] + $valid)->assertSessionHasErrors('subnet');
        $post(['gateway' => '10.31.0.1'] + $valid)->assertSessionHasErrors('gateway');
        $post(['pool' => '10.30.0.10-10.30.1.20'] + $valid)->assertSessionHasErrors('pool');
        $post(['pool' => '10.30.0.250-10.30.0.10'] + $valid)->assertSessionHasErrors('pool');
        $post(['pool' => '10.30.0.10-10.30.0.256'] + $valid)->assertSessionHasErrors('pool');
        $this->assertNull($this->site->fresh()->setting('setup'));

        $post($valid)->assertSessionHasNoErrors();
        $this->assertSame('10.30.0.1', $this->site->fresh()->setting('setup.gateway'));

        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup/container", [
            'veth_address' => '172.17.0.2/24', 'veth_gateway' => '172.18.0.1', 'bridge' => 'containers',
            'disk' => 'usb1', 'image' => 'ghcr.io/owner/repo:latest',
        ])->assertSessionHasErrors('veth_gateway');
    }

    public function test_login_page_fetch_requires_token_and_nas_ip(): void
    {
        $url = "/router-login/{$this->nas->id}/login.html";
        $this->onZone('guest')->get($url)->assertForbidden();
        // Unknown router: same answer as a wrong token (no 404).
        $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => '10.0.1.1'])
            ->withHeader('X-Portal-Token', $this->nas->cert_token)->get('/router-login/999/login.html')->assertForbidden();
        $this->onZone('guest')->get('/router-cert/999/fingerprint')->assertForbidden();
        $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => '10.0.1.1'])
            ->withHeader('X-Portal-Token', 'wrong')->get($url)->assertForbidden();
        $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => '10.0.9.9'])
            ->withHeader('X-Portal-Token', $this->nas->cert_token)->get($url)->assertForbidden();
        $this->withServerVariables(['PORTAL_ZONE' => 'admin', 'REMOTE_ADDR' => '10.0.1.1'])
            ->withHeader('X-Portal-Token', $this->nas->cert_token)->get($url)->assertNotFound();
        $this->assertNull($this->nas->fresh()->login_page_fetched_at);

        $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => '10.0.1.1'])
            ->withHeader('X-Portal-Token', $this->nas->cert_token)->get($url)->assertOk()
            ->assertSee('portal?site=site-a', false)->assertCookieMissing('portal_guest_session');
        $this->assertNotNull($this->nas->fresh()->login_page_fetched_at);
    }

    public function test_router_fetches_four_hotspot_pages_with_their_redirect_targets(): void
    {
        $get = fn (string $page, string $ip = '10.0.1.1', ?string $token = null) => $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => $ip])
            ->withHeader('X-Portal-Token', $token ?? $this->nas->cert_token)->get("/router-login/{$this->nas->id}/{$page}.html");
        $vars = '&amp;mac=$(mac-esc)&amp;ip=$(ip)&amp;link-login-only=$(link-login-only-esc)';

        // Same authentication for every page; an unknown page is 404, also before the token check.
        $get('status', '10.0.9.9')->assertForbidden();
        $get('logout', token: 'wrong')->assertForbidden();
        $get('rlogin')->assertNotFound();
        $this->assertNull($this->nas->fresh()->login_page_fetched_at);

        $get('status')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('url=https://portal.example.com/portal/status?site=site-a'.$vars.'"', false);
        $this->assertNotNull($this->nas->fresh()->login_page_fetched_at);
        $get('logout')->assertOk()->assertSee('url=https://portal.example.com/portal?site=site-a'.$vars.'"', false);
        $get('login')->assertOk()->assertSee('url=https://portal.example.com/portal?site=site-a'.$vars.'&amp;link-orig=$(link-orig-esc)"', false);
        $alogin = $get('alogin')->assertOk()->assertSee('url=$(link-redirect)"', false)->assertSee('<a href="$(link-redirect)">', false)->getContent();
        // No JavaScript, no popup, and no "$(" besides the RouterOS variables (RouterOS reads every "$(" as one).
        foreach (['login', 'status', 'logout', 'alogin'] as $page) {
            $html = $get($page)->getContent();
            $this->assertStringNotContainsString('<script', $html);
            $this->assertSame([], array_diff(preg_match_all('/\$\(([a-z-]+)\)/', $html, $m) ? $m[1] : [],
                ['mac-esc', 'ip', 'link-login-only-esc', 'link-orig-esc', 'link-redirect']));
            $this->assertSame(substr_count($html, '$('), count($m[1]));
        }
        $this->assertStringNotContainsString('popup', $alogin);

        // The fetch block of the assistant writes all four into the html-directory: one { ... } block (pasted into the
        // terminal, RouterOS runs the fetches one after another, decision 2026-11-05, v0.30.0), then one check per page.
        $fetch = $this->site->driver()->loginPageFetch($this->site, $this->nas);
        $this->assertSame(4, substr_count($fetch, '/tool fetch '));
        $code = array_values(array_filter(explode("\n", $fetch), fn ($line) => ! str_starts_with($line, '#')));
        $this->assertSame('{', $code[0]);
        $this->assertSame('}', end($code));
        $this->assertSame(1, substr_count($fetch, "\n{\n"));
        $this->assertSame(4, preg_match_all('/^    :if \(\[:len \[\/file find where name="hotspot\/[a-z]+\.html"\]\] > 0\) do=\{:put /m', $fetch));
        foreach (['login', 'status', 'logout', 'alogin'] as $page) {
            $this->assertStringContainsString("/router-login/{$this->nas->id}/{$page}.html\"", $fetch);
            $this->assertStringContainsString("dst-path=hotspot/{$page}.html} on-error={:put \"FAILED fetch {$page}.html\"}", $fetch);
            $this->assertStringContainsString(":put (\"ok {$page}.html (\" . [/file get [find where name=\"hotspot/{$page}.html\"] size] . \" bytes)\")", $fetch);
            $this->assertStringContainsString("else={:put \"MISSING {$page}.html\"}", $fetch);
        }
        // The fetches come before the checks
        $this->assertLessThan(strpos($fetch, ':if ('), strrpos($fetch, '/tool fetch '));

        // Admin download offers every page.
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee('data-hotspot-page="alogin"', false)->assertSee(__('portal.sites.download_page', ['file' => 'status.html']));
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/alogin.html")->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="alogin.html"')->assertSee('$(link-redirect)', false);
    }

    public function test_radius_request_updates_last_seen_without_rewriting_clients_file(): void
    {
        $this->assertNull($this->nas->last_seen_at);
        $file = config('portal.radius.clients_file');
        @unlink($file);

        $this->onZone('internal')->withHeader('Authorization', 'Basic '.base64_encode('radius:test-internal-token'))
            ->postJson('/internal/radius/authorize', [
                'FreeRADIUS-Client-Shortname' => ['type' => 'string', 'value' => [$this->nas->shortname()]],
                'User-Name' => ['type' => 'string', 'value' => ['nobody']],
            ])->assertStatus(401);

        $this->assertNotNull($this->nas->fresh()->last_seen_at);
        $this->assertFileDoesNotExist($file);

        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee(__('portal.setup.last_seen'));
    }

    public function test_captive_portal_api_reports_state_by_client_ip(): void
    {
        $api = fn (string $ip) => $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => $ip])->get('/captive/site-a');

        $api('10.30.0.20')->assertOk()->assertHeader('Content-Type', 'application/captive+json')
            ->assertJson(['captive' => true, 'user-portal-url' => 'https://hotspot-a.guest.example.com/login']);

        $grant = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin]);
        $summary = SessionSummary::create(['grant_id' => $grant->id, 'site_id' => $this->site->id, 'mac' => 'AA:BB:CC:00:00:01',
            'ip' => '10.30.0.20', 'started_at' => now(), 'serving_instance_id' => null]);
        RadiusAccounting::create(['session_summary_id' => $summary->id, 'nas_client_id' => $this->nas->id,
            'acct_session_id' => 's1', 'username' => 'u', 'last_update_at' => now()]);

        $api('10.30.0.20')->assertJson(['captive' => false]);
        $api('10.30.0.21')->assertJson(['captive' => true]);
        $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => '10.30.0.20'])->get('/captive/unknown')
            ->assertOk()->assertExactJson(['captive' => true]);
    }

    public function test_hotspot_host_must_differ_from_portal_host(): void
    {
        $admin = User::factory()->admin()->create();
        $base = ['name' => 'Site A', 'slug' => 'site-a', 'driver' => 'mikrotik_ros7'];
        $this->onZone('admin')->actingAs($admin)->put("/admin/sites/{$this->site->id}", $base + ['hotspot_hosts' => 'hotspot-a.guest.example.com, Portal.example.com'])
            ->assertSessionHasErrors(['hotspot_hosts' => __('portal.sites.hotspot_is_portal', ['host' => 'portal.example.com'])]);
        $this->onZone('admin')->actingAs($admin)->put("/admin/sites/{$this->site->id}", $base + ['hotspot_hosts' => 'portal.example.com.'])
            ->assertSessionHasErrors('hotspot_hosts');
        $this->onZone('admin')->actingAs($admin)->put("/admin/sites/{$this->site->id}", $base + ['portal_url' => 'https://Guest.Example.com./',
            'hotspot_hosts' => 'guest.example.com'])->assertSessionHasErrors('hotspot_hosts');
        $this->onZone('admin')->actingAs($admin)->put("/admin/sites/{$this->site->id}", $base + ['portal_url' => 'https://guest.example.com',
            'hotspot_hosts' => 'guest.example.com'])->assertSessionHasErrors('hotspot_hosts');
        $this->assertSame(['hotspot-a.guest.example.com'], $this->site->fresh()->setting('hotspot_hosts'));

        app()->setLocale('de');
        $this->assertStringContainsString('ist der Portal-Hostname', __('portal.sites.hotspot_is_portal', ['host' => 'x']));
        app()->setLocale('en');

        $this->onZone('admin')->actingAs($admin)->put("/admin/sites/{$this->site->id}", $base + ['portal_url' => 'https://guest.example.com',
            'hotspot_hosts' => 'hotspot-a.guest.example.com'])->assertSessionHasNoErrors();
    }

    public function test_page_shows_bridge_check_both_dns_entries_checklist_and_uninstall(): void
    {
        $admin = User::factory()->admin()->create();
        $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => ['vlan_id' => 30, 'bridge' => 'bridge1',
            'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24', 'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250',
            'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2']])]);

        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee(__('portal.setup.checklist.warning'))
            ->assertSee(__('portal.setup.network.bridge_help'))
            ->assertSee('/interface bridge port print where bridge=bridge1', false)
            ->assertSee('/interface bridge vlan add bridge=bridge1 tagged=bridge1,<TRUNK_PORT_TO_CAP> vlan-ids=30')
            ->assertDontSee('/interface bridge set bridge1 vlan-filtering=yes', false)
            ->assertDontSee('add name=hotspot-a.guest.example.com', false)
            ->assertSee('add name=guest-portal-v4.internal address=10.0.0.10', false)
            ->assertSee('add name=portal.example.com type=CNAME cname=guest-portal-v4.internal', false)
            ->assertSee('/ip hotspot profile print where name=guest-portal', false)
            ->assertSee('/certificate print where name="guest-portal" || name~"^guest-portal-ca-[0-9]+\\$"')
            ->assertSee('/ip hotspot profile print where default=yes', false)
            ->assertSee('/ip hotspot walled-garden ip print', false)
            ->assertSee('/file print where name~"(login|status|logout|alogin).html"')
            ->assertSee(__('portal.setup.uninstall.title'));
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk()
            ->assertSee(__('portal.setup.checklist.warning'))->assertSee(__('portal.sites.nas_ip'));

        $u = $this->site->fresh()->driver()->setupCommands($this->site->fresh(), [])['uninstall'];
        $script = $u['uninstall'];
        foreach (['/system scheduler remove [find where name="guest-portal-cert" && ((comment="guest-portal:cert:scheduler" || comment="guest-portal" || comment~"^guest-portal: ") || !comment)]',
            '/system script remove [find where name="guest-portal-cert" && ((comment="guest-portal:cert:script" || comment="guest-portal" || comment~"^guest-portal: ") || !comment)]',
            '/ip hotspot remove [find where name="guest-portal"]', '/ip hotspot profile remove [find where name="guest-portal"]',
            '/ip hotspot walled-garden remove [find where ((comment="guest-portal:walled:portal-host" || comment="guest-portal" || comment~"^guest-portal: ") && !dynamic)]', '/ip hotspot walled-garden ip remove [find where ((comment="guest-portal:walled:portal-ip" || comment="guest-portal" || comment~"^guest-portal: ") && !dynamic)]',
            '/radius remove [find where (comment="guest-portal:radius:server" || comment="guest-portal" || comment~"^guest-portal: ")]', '# /radius incoming set accept=no',
            '/certificate remove [find where name="guest-portal" || name~"^guest-portal-ca-[0-9]+\\$" || name~"^guest-portal-import" || name="guest-portal-new" || name~"^guest-portal-new_"]',
            '/ip dns static remove [find where ((comment="guest-portal:dns:hotspot-name" || comment="guest-portal:dns:portal-a" || comment="guest-portal:dns:portal-cname" || comment="guest-portal" || comment~"^guest-portal: ") && !dynamic)]',
            '/ip dhcp-server network find where address="10.30.0.0/24" && (comment="guest-portal:net:dhcp-network" || comment="guest-portal" || comment~"^guest-portal: ")]',
            '/ip dhcp-server network set $gpNet dhcp-option=""', '/ip dhcp-server option remove [find where name="captive-portal" && (comment="guest-portal:net:option-114" || comment="guest-portal" || comment~"^guest-portal: ")]',
            '/ip firewall nat remove [find where comment~"^guest-portal: " && !dynamic]',
            '/ip firewall address-list remove [find where (list=PORTAL || list=PORTAL_RADIUS || list=RFC1918) && ((comment="guest-portal:fw:addr-portal" || comment="guest-portal:fw:addr-radius" || comment="guest-portal:fw:addr-rfc1918-10" || comment="guest-portal:fw:addr-rfc1918-172" || comment="guest-portal:fw:addr-rfc1918-192" || comment="guest-portal" || comment~"^guest-portal: ") && !dynamic)]'] as $line) {
            $this->assertStringContainsString($line, $script);
        }
        // Order: hotspot before profile, profile before certificates, option reference before the option.
        $this->assertLessThan(strpos($script, '/ip hotspot profile remove'), strpos($script, '/ip hotspot remove'));
        $this->assertLessThan(strpos($script, '/certificate remove'), strpos($script, '/ip hotspot profile remove'));
        $this->assertLessThan(strpos($script, '/ip dhcp-server option remove'), strpos($script, 'dhcp-option=""'));
        // Never a prefix match that would hit user certificates like "guest-portal.example.com".
        $this->assertStringNotContainsString('name~"^guest-portal"', $script);
        $this->assertStringNotContainsString('dhcp-option~', $script);
        // No prefix match without the ": " separator (foreign comments like "guest-portal-old" stay).
        $this->assertStringNotContainsString('comment~"^guest-portal"]', $script);
        $this->assertStringContainsString('add name=captive-portal code=114 value="\'https://portal.example.com/captive/site-a\'" comment="guest-portal:net:option-114"',
            $this->site->fresh()->driver()->setupCommands($this->site->fresh(), [])['network']['captive_option']);
        $this->assertStringContainsString('/interface/veth remove', str_replace('/interface veth', '/interface/veth', $u['uninstall_container']));
        foreach (['/interface wifi configuration remove [find where name=cfg-guest && (comment="guest-portal:wifi:cfg-guest" || comment="guest-portal" || comment~"^guest-portal: ")]',
            '/ip dhcp-server remove [find where name=guest-dhcp && (comment="guest-portal:net:dhcp-server" || comment="guest-portal" || comment~"^guest-portal: ")]',
            '/ip pool remove [find where name=guest-pool && (comment="guest-portal:net:pool" || comment="guest-portal" || comment~"^guest-portal: ")]',
            '/interface vlan remove [find where name=vlan-guest && (comment="guest-portal:net:vlan" || comment="guest-portal" || comment~"^guest-portal: ")]',
            '/interface list remove [find where name=GUEST && (comment="guest-portal:fw:list-guest" || comment="guest-portal" || comment~"^guest-portal: ")]',
            '/ip address remove [find where interface=vlan-guest && (comment="guest-portal:net:gateway" || comment="guest-portal" || comment~"^guest-portal: ")]'] as $line) {
            $this->assertStringContainsString($line, $u['uninstall_network']);
        }
        // Every remove of the optional blocks is limited to objects with the assistant's comment.
        foreach (['uninstall_network', 'uninstall_container'] as $block) {
            foreach (preg_split('/\n/', $u[$block]) as $line) {
                if (preg_match('/^\/\S.* remove \[find/', $line) && ! str_contains($line, 'interface=veth-portal]')) {
                    $this->assertStringContainsString(' || comment="guest-portal" || comment~"^guest-portal: ")]', $line, $line);
                    $this->assertMatchesRegularExpression('/comment="guest-portal:(net|wifi|fw|container):[a-z0-9-]+"/', $line);
                }
            }
        }
        $this->assertLessThan(strpos($u['uninstall_network'], '/interface vlan remove'), strpos($u['uninstall_network'], '/ip address remove'));
    }

    /** Findings of the RouterOS CHR harness (tests/routeros): re-runnable blocks, no foreign objects touched. */
    public function test_blocks_never_touch_foreign_objects_and_are_re_runnable(): void
    {
        $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => ['vlan_id' => 30, 'bridge' => 'bridge1',
            'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24', 'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250',
            'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2']])]);
        $site = $this->site->fresh();
        $c = $site->driver()->setupCommands($site, []);
        $firewall = $c['network']['firewall'];

        // An existing RFC1918 entry without our comment made "add" fail ("already have such entry").
        $this->assertStringContainsString(':if ([:len [find where list=RFC1918 && address=10.0.0.0/8]] = 0) do={ add list=RFC1918 address=10.0.0.0/8 comment="guest-portal:fw:addr-rfc1918-10" }', $firewall);
        $this->assertStringContainsString(':if ([:len [/interface list find where name=GUEST]] = 0) do={ /interface list add name=GUEST comment="guest-portal:fw:list-guest" }', $firewall);
        $this->assertStringContainsString('remove [find where (list=PORTAL || list=PORTAL_RADIUS || list=RFC1918) && ((comment="guest-portal:fw:addr-portal" || comment="guest-portal:fw:addr-radius" || comment="guest-portal:fw:addr-rfc1918-10" || comment="guest-portal:fw:addr-rfc1918-172" || comment="guest-portal:fw:addr-rfc1918-192" || comment="guest-portal" || comment~"^guest-portal: ") && !dynamic)]', $firewall);
        $this->assertDoesNotMatchRegularExpression('/^add list=/m', $firewall);

        // Option 114: updated in place when it is ours, the network is matched by subnet and comment.
        $this->assertStringContainsString('/ip dhcp-server option set [find where name="captive-portal" && (comment="guest-portal:net:option-114" || comment="guest-portal" || comment~"^guest-portal: ")] code=114', $c['network']['captive_option']);
        $this->assertStringContainsString('[find where address="10.30.0.0/24" && (comment="guest-portal:net:dhcp-network" || comment="guest-portal" || comment~"^guest-portal: ")] dhcp-option=captive-portal', $c['network']['captive_option']);
        // Network objects: added, or updated by id when they are ours (re-runnable, legacy comment replaced)
        foreach (['/interface vlan add name=vlan-guest interface=bridge1 vlan-id=30 comment="guest-portal:net:vlan"',
            '/interface vlan set [find where name=vlan-guest && (comment="guest-portal:net:vlan" || comment="guest-portal" || comment~"^guest-portal: ")] interface=bridge1 vlan-id=30 comment="guest-portal:net:vlan"',
            ':if ([:len [/ip pool find where name=guest-pool && (comment="guest-portal:net:pool" || comment="guest-portal" || comment~"^guest-portal: ")]] = 0) do={',
            '/ip pool add name=guest-pool ranges=10.30.0.10-10.30.0.250 comment="guest-portal:net:pool"',
            '/ip pool set [find where name=guest-pool && (comment="guest-portal:net:pool" || comment="guest-portal" || comment~"^guest-portal: ")] ranges=10.30.0.10-10.30.0.250 comment="guest-portal:net:pool"',
            'dns-server=10.30.0.1 comment="guest-portal:net:dhcp-network"'] as $line) {
            $this->assertStringContainsString($line, $c['network']['vlan'].$c['network']['dhcp']);
        }

        // Walled garden ip: only the own entry is replaced; a foreign drop entry is reported, not changed.
        $script = $site->driver()->configScript($site, $this->nas);
        $this->assertStringContainsString("/ip hotspot walled-garden ip\n".'remove [find where ((comment="guest-portal:walled:portal-ip" || comment="guest-portal" || comment~"^guest-portal: ") && !dynamic)]', $script);
        // Dynamic walled-garden entries of the hotspot (comment "guest-portal" = server name) are never removed
        $this->assertStringContainsString("/ip hotspot walled-garden\n".'remove [find where ((comment="guest-portal:walled:portal-host" || comment="guest-portal" || comment~"^guest-portal: ") && !dynamic)]', $script);
        $this->assertSame(1, substr_count($script, 'walled-garden ip'."\n".'remove'));
        $this->assertStringContainsString('/ip hotspot walled-garden ip print', $c['diagnostics']['verify']);
        $this->assertStringContainsString('action=drop', $c['diagnostics']['verify']);
    }

    public function test_hotspot_html_directory_is_set_on_the_profile_and_used_by_the_fetch(): void
    {
        $admin = User::factory()->admin()->create();
        $form = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24',
            'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2'];
        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup", $form + ['html_directory' => '../etc'])
            ->assertSessionHasErrors('html_directory');

        // Default (older saved setups without the field): "hotspot"
        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup", $form)->assertRedirect();
        $site = $this->site->fresh();
        $this->assertStringContainsString('html-directory=hotspot ', $site->driver()->configScript($site, $this->nas));
        $this->assertStringContainsString('dst-path=hotspot/login.html', $site->driver()->loginPageFetch($site, $this->nas));

        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup", $form + ['html_directory' => 'flash/hotspot'])
            ->assertRedirect();
        $site = $this->site->fresh();
        $this->assertSame('flash/hotspot', $site->setting('setup.html_directory'));
        $this->assertStringContainsString('html-directory=flash/hotspot ', $site->driver()->setupCommands($site, [])['hotspot']['hotspot']);
        $this->assertStringContainsString('dst-path=flash/hotspot/login.html', $site->driver()->loginPageFetch($site, $this->nas));
        $this->assertStringContainsString('/ip hotspot profile print where default=yes', $site->driver()->setupCommands($site, [])['diagnostics']['verify']);
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee('<option value="flash/hotspot" selected>', false);
    }

    public function test_certificate_script_identifies_leaf_by_fingerprint_and_profile_reference(): void
    {
        $script = $this->site->driver()->configScript($this->site, $this->nas);
        foreach ([
            ':local gpTmp "guest-portal-import"',
            ':local gpProfs [/ip hotspot profile find where name=$gpName]',
            ':local gpSc [:tostr [/ip hotspot profile get $gpProf ssl-certificate]]',
            ':foreach c in=[/certificate find where name=$gpSc] do={ :set gpCur [$gpNorm [/certificate get $c fingerprint]] }',
            '/certificate import file-name=($gpName . ".crt") passphrase="" name=$gpTmp',
            ':if ([$gpNorm [/certificate get $c fingerprint]] = $gpFp) do={ :set gpLeaf $c }',
            '/certificate find where name~("^" . $gpTmp) && private-key',
            '/ip hotspot profile set $gpProf ssl-certificate=[/certificate get $gpLeaf name] login-by=https,mac',
            ':if ([$gpRemove $gpName $gpTmp "pre"] < 0) do={ :error',
            ':if ([$gpRemove $gpName $gpTmp "post"] < 0) do={ :error',
            ':foreach gc in=[/certificate find] do={',
            ':foreach gc in=$gdel do={ /certificate remove $gc }',
            ':if ([:len $gdel] > 10) do={',
            ':foreach gu in=$gused do={ :if ($gu = $gn) do={ :set gwant false } }',
            '/certificate set $c name=($gpName . "-ca-" . $gpIdx)',
            ':onerror gpErr in={',
            'router certificate update failed at step',
        ] as $line) {
            $this->assertStringContainsString($line, $script);
        }
        // Removal order: temporary/CA/legacy before the import, previous leaf after the profile switch.
        $this->assertLessThan(strpos($script, '/certificate import'), strpos($script, '"pre"] < 0'));
        $this->assertLessThan(strpos($script, '"post"] < 0'), strpos($script, 'ssl-certificate=[/certificate get $gpLeaf name]'));
        $this->assertStringNotContainsString('name=guest-portal-new ', $script);
        $this->assertStringContainsString('paste this into the terminal instead of saving it as a /system', $script);
        $this->assertStringContainsString('logins of the portal CANNOT work yet', $script);
    }

    public function test_certificate_script_never_removes_by_find_filter(): void
    {
        $script = $this->site->driver()->configScript($this->site, $this->nas);
        $this->assertStringNotContainsString('/certificate remove [find', $script);
        $this->assertStringNotContainsString('certificate remove [find', $script);
        // Every certificate removal in the certificate script takes a single id from the checked list.
        preg_match_all('/\/certificate remove ([^\n]*)/', $script, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $arg) {
            $this->assertSame('$gc }', trim($arg), $arg);
        }
    }

    /**
     * RouterOS gotcha: inside "find where", a local with the same name as a property is not
     * substituted ("name=$name" compares the property with itself and matches every item).
     */
    public function test_generated_script_locals_never_shadow_find_where_properties(): void
    {
        $this->site->update(['settings' => array_merge($this->site->settings ?? [], ['setup' => [
            'vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24',
            'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2',
        ]])]);
        $site = $this->site->fresh();
        $driver = $site->driver();
        $scripts = ['config' => $driver->configScript($site, $this->nas), 'login_fetch' => $driver->loginPageFetch($site, $this->nas)];
        $container = ['veth_address' => '172.17.0.2/24', 'veth_gateway' => '172.17.0.1', 'bridge' => 'containers', 'disk' => 'usb1'];
        $commands = $driver->setupCommands($site, $container);
        array_walk_recursive($commands, function ($text, $key) use (&$scripts) {
            if (is_string($text)) {
                $scripts['setup:'.$key] = $text;
            }
        });
        $this->assertGreaterThan(10, count($scripts));

        $base = ['name', 'comment', 'address', 'interface', 'disabled', 'type', 'list', 'value'];
        $checkedLocals = 0;
        foreach ($scripts as $label => $text) {
            // Property names used in find clauses of this script ("find where a=..", "find a=..", "a~..", "a!=..")
            $props = $base;
            preg_match_all('/find(?: where)? ([^\]\n]*)/', $text, $finds);
            foreach ($finds[1] as $clause) {
                preg_match_all('/([a-z][a-z0-9-]*)\s*(?:!=|=|~)/', $clause, $p);
                $props = array_merge($props, $p[1]);
            }
            $props = array_unique($props);
            // Script variables: :local X, :global X, :foreach X in=, :for X from=, :onerror X in=
            preg_match_all('/:(?:local|global|foreach|for|onerror)\s+([A-Za-z][A-Za-z0-9-]*)/', $text, $vars);
            foreach ($vars[1] as $var) {
                $checkedLocals++;
                $this->assertNotContains($var, $props, "$label: variable \"$var\" shadows a property used in find where");
            }
        }
        $this->assertGreaterThan(20, $checkedLocals);
        $this->assertStringContainsString(':local gpName "guest-portal"', $scripts['config']);
        $this->assertStringNotContainsString(':local name ', $scripts['config']);
    }

    public function test_generated_scripts_never_use_comment_on_certificates(): void
    {
        $script = $this->site->driver()->configScript($this->site, $this->nas);
        $uninstall = $this->site->driver()->setupCommands($this->site, [])['uninstall']['uninstall'];
        foreach ([$script, $uninstall] as $text) {
            $lines = array_filter(explode("\n", $text), fn ($l) => str_contains($l, '/certificate') && ! str_starts_with(ltrim($l), '#'));
            $this->assertNotEmpty($lines);
            foreach ($lines as $line) {
                $this->assertStringNotContainsString('comment', $line, $line);
            }
        }
        // RouterOS certificates have no comment: no certificate statement inside the cert script uses it.
        $this->assertDoesNotMatchRegularExpression('/^\s*(\/certificate|:foreach c in=\[\/certificate)[^\n]*comment/m', $script);
    }

    public function test_dns_block_pins_portal_host_as_cname_to_internal_a_record(): void
    {
        $this->site->update(['settings' => array_merge($this->site->settings, ['portal_address' => '10.0.0.20',
            'setup' => ['subnet' => '10.30.0.0/24', 'gateway' => '10.30.0.1']])]);
        $dns = $this->site->fresh()->driver()->setupCommands($this->site->fresh(), [])['hotspot']['dns'];

        // The own hotspot-name entry of earlier versions is only removed (decision 2026-11-02: RouterOS adds a
        // dynamic entry for the hotspot dns-name; dynamic entries are never touched)
        $remove = 'remove [find where ((comment="guest-portal:dns:hotspot-name" || comment="guest-portal:dns:portal-a" || comment="guest-portal:dns:portal-cname" || comment="guest-portal" || comment~"^guest-portal: ") && !dynamic)]';
        $this->assertStringContainsString($remove."\nadd name=guest-portal-v4.internal", $dns);
        $this->assertStringNotContainsString('add name=hotspot-a.guest.example.com', $dns);
        $this->assertSame(1, substr_count($dns, 'comment="guest-portal:dns:hotspot-name"'));
        $this->assertStringContainsString('add name=guest-portal-v4.internal address=10.0.0.20 comment="guest-portal:dns:portal-a"', $dns);
        $this->assertStringContainsString('add name=portal.example.com type=CNAME cname=guest-portal-v4.internal comment="guest-portal:dns:portal-cname"', $dns);
        $this->assertStringNotContainsString('add name=portal.example.com address=', $dns);
        $this->assertStringNotContainsString('type=NXDOMAIN', preg_replace('/^#.*$/m', '', $dns));
        $this->assertLessThan(strpos($dns, 'add name=guest-portal-v4.internal'), strpos($dns, $remove));
    }

    public function test_portal_address_must_be_ipv4_outside_the_guest_network(): void
    {
        $admin = User::factory()->admin()->create();
        $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => ['subnet' => '10.30.0.0/24', 'gateway' => '10.30.0.1']])]);
        $base = ['name' => 'Site A', 'slug' => 'site-a', 'driver' => 'mikrotik_ros7', 'radius_address' => '10.0.0.10'];
        $put = fn (string $ip) => $this->onZone('admin')->actingAs($admin)->put("/admin/sites/{$this->site->id}", $base + ['portal_address' => $ip]);

        foreach (['10.30.0.1' => 'portal.sites.portal_address_is_gateway', '10.30.0.99' => 'portal.sites.portal_address_in_guest_subnet'] as $ip => $key) {
            $put($ip)->assertSessionHasErrors(['portal_address' => __($key, ['ip' => $ip, 'subnet' => '10.30.0.0/24'])]);
        }
        $put('fd00::1')->assertSessionHasErrors('portal_address');
        $put('not-an-ip')->assertSessionHasErrors('portal_address');
        $this->assertNull($this->site->fresh()->setting('portal_address'));

        $put('10.0.0.20')->assertSessionHasNoErrors();
        $this->assertSame('10.0.0.20', $this->site->fresh()->setting('portal_address'));
        $put('')->assertSessionHasNoErrors();

        // German message and label
        $this->onZone('admin')->actingAs($admin)->withSession(['locale' => 'de'])->put("/admin/sites/{$this->site->id}", $base + ['portal_address' => '10.30.0.1'])
            ->assertSessionHasErrors(['portal_address' => 'Die Portal-IP 10.30.0.1 ist das Gast-Gateway (der Router selbst). Bitte die IP-Adresse der Portal-Instanz angeben, wie der Router sie erreicht (z. B. IP des Docker-Hosts bzw. Containers), oder leer lassen.']);

        // A stored value violating the rule (e.g. saved before v0.12.3) shows a warning in the assistant.
        $this->site->update(['settings' => array_merge($this->site->fresh()->settings, ['portal_address' => '10.30.0.1'])]);
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee(__('portal.setup.hotspot.portal_address_warning'))
            ->assertSee(__('portal.sites.portal_address_is_gateway', ['ip' => '10.30.0.1']));
        $this->site->update(['settings' => array_merge($this->site->fresh()->settings, ['portal_address' => '10.0.0.20'])]);
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertDontSee(__('portal.setup.hotspot.portal_address_warning'));
    }

    public function test_regenerate_rotates_secret_and_token_and_old_token_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $oldSecret = $this->nas->radius_secret;
        $oldToken = $this->nas->cert_token;
        $url = "/router-cert/{$this->nas->id}/fingerprint";

        $this->onZone('admin')->actingAs($admin)
            ->post("/admin/sites/{$this->site->id}/routers/{$this->nas->id}/secret")->assertRedirect();

        $fresh = NasClient::find($this->nas->id);
        $this->assertNotSame($oldSecret, $fresh->radius_secret);
        $this->assertNotSame($oldToken, $fresh->cert_token);
        $this->assertSame(32, strlen($fresh->cert_token));

        $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => '10.0.1.1'])
            ->withHeader('X-Portal-Token', $oldToken)->get($url)->assertForbidden();
        // The new token passes the token check (no certificate is stored here, so not 403).
        $this->assertNotSame(403, $this->withServerVariables(['PORTAL_ZONE' => 'guest', 'REMOTE_ADDR' => '10.0.1.1'])
            ->withHeader('X-Portal-Token', $fresh->cert_token)->get($url)->getStatusCode());
    }
}
