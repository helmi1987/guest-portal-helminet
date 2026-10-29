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
            'vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan30-guest', 'subnet' => '10.30.0.0/24',
            'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman1',
        ])->assertRedirect();
        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup/container", [
            'veth_address' => '172.17.0.2/24', 'veth_gateway' => '172.17.0.1', 'bridge' => 'containers',
            'disk' => 'usb1', 'image' => 'ghcr.io/owner/repo:latest',
        ])->assertRedirect();

        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee('/interface vlan add name=vlan30-guest interface=bridge1 vlan-id=30', false)
            ->assertSee('/caps-man datapath add name=dp-guest bridge=bridge1 vlan-id=30', false)
            ->assertSee('ssid="Guest-WiFi"')
            ->assertSee('/ip address add address=10.30.0.1/24 interface=vlan30-guest', false)
            ->assertSee('/ip pool add name=guest-pool ranges=10.30.0.10-10.30.0.250', false)
            ->assertSee('value="\'https://portal.example.com/captive/site-a\'"')
            ->assertSee('add name=hotspot-a.guest.example.com address=10.30.0.1', false)
            ->assertSee('/ip/address add address=172.17.0.1/24 interface=containers', false)
            ->assertSee('mountlists=portal-data envlist=portal', false)
            ->assertSee('remote-image=ghcr.io/owner/repo:latest', false)
            ->assertSee("/router-login/{$this->nas->id}/login.html", false)
            ->assertSee('X-Portal-Token: '.$this->nas->cert_token, false)
            ->assertSee('/radius monitor 0 once', false)
            ->assertSee('key=APP_URL value="https://portal.example.com"')
            ->assertSee('slave-configurations=<EXISTING_SLAVES>,cfg-guest');

        $script = $this->site->fresh()->driver()->configScript($this->site->fresh(), $this->nas);
        $this->assertStringContainsString('hotspot-address=10.30.0.1', $script);
        $this->assertStringContainsString('interface=vlan30-guest address-pool=guest-pool profile=guest-portal', $script);
    }

    public function test_values_are_validated_and_sanitized_against_script_injection(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup", [
            'vlan_id' => 30, 'bridge' => 'bridge1; /system reset-configuration', 'interface' => 'vlan30',
            'subnet' => '10.30.0.0/24', 'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250',
            'ssid' => 'Guest"; /system reset-configuration; "', 'wifi_mode' => 'capsman2',
        ])->assertSessionHasErrors(['bridge', 'ssid']);
        $this->assertNull($this->site->fresh()->setting('setup'));

        // Values bypassing validation (e.g. replicated from elsewhere) are stripped on output.
        $this->site->update(['settings' => array_merge($this->site->settings, ['setup' => [
            'vlan_id' => 'x30 disabled=yes', 'bridge' => 'br1 [/system reset-configuration]', 'interface' => 'vlan30"$x',
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
        $this->assertStringContainsString('interface=vlan30x', $all);
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
        $this->assertStringContainsString('src-address-list=PORTAL_RADIUS dst-port=1700,3799 comment="guest-portal: Disconnect/CoA"', $firewall);
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
        $valid = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan30-guest', 'subnet' => '10.30.0.0/24',
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
            'interface' => 'vlan30-guest', 'subnet' => '10.30.0.0/24', 'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250',
            'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2']])]);

        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()
            ->assertSee(__('portal.setup.checklist.warning'))
            ->assertSee(__('portal.setup.network.bridge_help'))
            ->assertSee('/interface bridge port print where bridge=bridge1', false)
            ->assertSee('/interface bridge vlan add bridge=bridge1 tagged=bridge1,<TRUNK_PORT_TO_CAP> vlan-ids=30')
            ->assertDontSee('/interface bridge set bridge1 vlan-filtering=yes', false)
            ->assertSee('add name=hotspot-a.guest.example.com address=10.30.0.1', false)
            ->assertSee('add name=guest-portal-v4.internal address=10.0.0.10', false)
            ->assertSee('add name=portal.example.com type=CNAME cname=guest-portal-v4.internal', false)
            ->assertSee('/ip hotspot profile print where name=guest-portal', false)
            ->assertSee('/certificate print where name~"guest-portal"')
            ->assertSee('/file print where name~"login.html"')
            ->assertSee(__('portal.setup.uninstall.title'));
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk()
            ->assertSee(__('portal.setup.checklist.warning'))->assertSee(__('portal.sites.nas_ip'));

        $u = $this->site->fresh()->driver()->setupCommands($this->site->fresh(), [])['uninstall'];
        $script = $u['uninstall'];
        foreach (['/system scheduler remove [find where name="guest-portal-cert"]', '/system script remove [find where name="guest-portal-cert"]',
            '/ip hotspot remove [find where name="guest-portal"]', '/ip hotspot profile remove [find where name="guest-portal"]',
            '/ip hotspot walled-garden remove [find where comment="guest-portal"]', '/ip hotspot walled-garden ip remove [find where comment="guest-portal"]',
            '/radius remove [find where comment="guest-portal"]', '# /radius incoming set accept=no',
            '/certificate remove [find where name="guest-portal" || name~"^guest-portal-ca-[0-9]+\\$" || name~"^guest-portal-import" || name="guest-portal-new" || name~"^guest-portal-new_"]',
            '/ip dns static remove [find where comment="guest-portal"]', '/ip dhcp-server network find where address="10.30.0.0/24"',
            '/ip dhcp-server network set $i dhcp-option=""', '/ip dhcp-server option remove [find where name="captive-portal" && comment="guest-portal"]',
            '/ip firewall filter remove [find where comment~"^guest-portal"]', '/ip firewall address-list remove [find where comment~"^guest-portal"]'] as $line) {
            $this->assertStringContainsString($line, $script);
        }
        // Order: hotspot before profile, profile before certificates, option reference before the option.
        $this->assertLessThan(strpos($script, '/ip hotspot profile remove'), strpos($script, '/ip hotspot remove'));
        $this->assertLessThan(strpos($script, '/certificate remove'), strpos($script, '/ip hotspot profile remove'));
        $this->assertLessThan(strpos($script, '/ip dhcp-server option remove'), strpos($script, 'dhcp-option=""'));
        // Never a prefix match that would hit user certificates like "guest-portal.example.com".
        $this->assertStringNotContainsString('name~"^guest-portal"', $script);
        $this->assertStringNotContainsString('dhcp-option~', $script);
        $this->assertStringContainsString('name=captive-portal value="\'https://portal.example.com/captive/site-a\'" comment="guest-portal"',
            $this->site->fresh()->driver()->setupCommands($this->site->fresh(), [])['network']['captive_option']);
        $this->assertStringContainsString('/interface/veth remove', str_replace('/interface veth', '/interface/veth', $u['uninstall_container']));
        foreach (['/interface wifi configuration remove [find where name=cfg-guest]', '/ip dhcp-server remove [find where name=guest-dhcp]',
            '/ip pool remove [find where name=guest-pool]', '/interface vlan remove [find where name=vlan30-guest]',
            '/interface list remove [find where name=GUEST]'] as $line) {
            $this->assertStringContainsString($line, $u['uninstall_network']);
        }
        $this->assertLessThan(strpos($u['uninstall_network'], '/interface vlan remove'), strpos($u['uninstall_network'], '/ip address remove'));
    }

    public function test_certificate_script_identifies_leaf_by_fingerprint_and_profile_reference(): void
    {
        $script = $this->site->driver()->configScript($this->site, $this->nas);
        foreach ([
            ':local tmp "guest-portal-import"',
            ':local sc [:tostr [/ip hotspot profile get $prof ssl-certificate]]',
            ':foreach c in=[/certificate find where name=$sc] do={ :set cur [$norm [/certificate get $c fingerprint]] }',
            '/certificate import file-name=($name . ".crt") passphrase="" name=$tmp',
            ':if ([$norm [/certificate get $c fingerprint]] = $fp) do={ :set leaf $c }',
            '/certificate find where name~("^" . $tmp) && private-key',
            '/ip hotspot profile set $prof ssl-certificate=[/certificate get $leaf name] login-by=https,mac',
            '/certificate remove [find where !(name~("^" . $tmp)) && (name=$name || name~("^" . $name . "-ca-[0-9]+\\$")',
            '/certificate remove [find where name!=$sc && (name~("^" . $name . "-ca-[0-9]+\\$")',
            '/certificate set $c name=($name . "-ca-" . $i)',
            ':onerror err in={',
            'router certificate update failed at step',
        ] as $line) {
            $this->assertStringContainsString($line, $script);
        }
        // No name-based comparison any more (chain imports create several certificates).
        $this->assertStringNotContainsString('find where name=$name] do={ :set cur', $script);
        $this->assertStringNotContainsString('name=guest-portal-new ', $script);
        $this->assertStringNotContainsString('name~("^" . $name) ', $script);
        $this->assertStringContainsString('paste this into the terminal instead of saving it as a /system', $script);
        $this->assertStringContainsString('logins of the portal CANNOT work yet', $script);
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

        $this->assertStringContainsString("remove [find comment=\"guest-portal\"]\nadd name=hotspot-a.guest.example.com address=10.30.0.1 comment=\"guest-portal\"", $dns);
        $this->assertStringContainsString('add name=guest-portal-v4.internal address=10.0.0.20 comment="guest-portal"', $dns);
        $this->assertStringContainsString('add name=portal.example.com type=CNAME cname=guest-portal-v4.internal comment="guest-portal"', $dns);
        $this->assertStringNotContainsString('add name=portal.example.com address=', $dns);
        $this->assertStringNotContainsString('type=NXDOMAIN', preg_replace('/^#.*$/m', '', $dns));
        $this->assertLessThan(strpos($dns, 'add name=guest-portal-v4.internal'), strpos($dns, 'remove [find comment="guest-portal"]'));
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
