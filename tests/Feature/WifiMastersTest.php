<?php

namespace Tests\Feature;

use App\Federation\Federation;
use App\Federation\Outbox;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use App\Nas\MikrotikRos7Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Guest Wi-Fi on 1..n masters (CONCEPT.md §9, decision 2026-11-01): selection per site and mode, local to
 * the instance; per-master script lines, router state and line colours.
 */
class WifiMastersTest extends TestCase
{
    use RefreshDatabase;

    private const NETWORK = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24',
        'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi', 'wifi_mode' => 'capsman2'];

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

    private function save(array $extra): TestResponse
    {
        $admin = User::factory()->admin()->create();

        return $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$this->site->id}/setup", $extra + self::NETWORK);
    }

    private function withMode(string $mode, array $masters): Site
    {
        $this->site->forceFill(['settings' => array_merge($this->site->settings, ['setup' => ['wifi_mode' => $mode] + self::NETWORK]),
            'wifi_masters_local' => [$mode => $masters]])->save();

        return Site::with('nasClients')->find($this->site->id);
    }

    private function wifi(Site $site): string
    {
        return $site->driver()->setupCommands($site, [], false)['network']['wifi'];
    }

    public function test_selection_is_saved_per_mode_locally_and_not_replicated(): void
    {
        $this->save(['wifi_masters' => ['capsman2' => ['cfg-main']], 'wifi_masters_text' => ['capsman2' => 'cfg-office, cfg-main', 'local' => 'wifi1 wifi2']])
            ->assertSessionHasNoErrors();
        $site = $this->site->fresh();
        $this->assertSame(['cfg-main', 'cfg-office'], $site->wifiMasters('capsman2'));
        $this->assertSame(['wifi1', 'wifi2'], $site->wifiMasters('local'));
        $this->assertSame([], $site->wifiMasters('capsman1'));
        $this->assertContains('wifi_masters_local', Federation::localColumns('sites'));
        $this->assertArrayNotHasKey('wifi_masters', (array) $site->setting('setup'));

        app(Outbox::class)->record('sites', $this->site->id);
        $payload = (string) DB::table('federation_events')->where('table', 'sites')->latest('seq')->value('payload');
        $this->assertStringNotContainsString('wifi_masters_local', $payload);
        $this->assertStringNotContainsString('cfg-office', $payload);

        foreach (['cfg-guest', 'wifi-guest-x', 'a"b', str_repeat('a', 49)] as $bad) {
            $this->save(['wifi_masters_text' => ['capsman1' => $bad]])->assertSessionHasErrors('wifi_masters_text.capsman1');
        }
        $this->save(['wifi_masters_text' => ['local' => implode(',', array_map(fn ($i) => 'wifi'.$i, range(1, 17)))]])
            ->assertSessionHasErrors('wifi_masters_text.local');
        $this->assertSame(['cfg-main', 'cfg-office'], $this->site->fresh()->wifiMasters('capsman2'));
    }

    public function test_non_string_ticks_are_discarded_and_trailing_newline_is_invalid(): void
    {
        $this->save(['wifi_masters' => ['capsman2' => ['cfg-main', ['x'], 5]]])->assertSessionHasNoErrors();
        $this->assertSame(['cfg-main'], $this->site->fresh()->wifiMasters('capsman2'));
        $this->assertFalse(Site::validWifiMaster("cfg-main\n"));
        $this->assertTrue(Site::validWifiMaster('cfg-main'));
    }

    public function test_hidden_modes_not_submitted_keep_stored_masters(): void
    {
        $this->withMode('local', ['wifi1']);
        $this->save(['wifi_masters_text' => ['capsman2' => 'cfg-main']])->assertSessionHasNoErrors();
        $site = $this->site->fresh();
        $this->assertSame(['wifi1'], $site->wifiMasters('local'));
        $this->assertSame(['cfg-main'], $site->wifiMasters('capsman2'));
    }

    public function test_capsman_appends_cfg_guest_per_selected_rule_and_keeps_existing_slaves(): void
    {
        foreach (['capsman1' => '/caps-man', 'capsman2' => '/interface wifi'] as $mode => $path) {
            $script = $this->wifi($this->withMode($mode, ['cfg-main', 'cfg-office']));
            $this->assertStringNotContainsString('<EXISTING_SLAVES>', $script);
            $this->assertStringNotContainsString('<MASTER_CFG>', $script);
            foreach (['cfg-main', 'cfg-office'] as $m) {
                $this->assertStringContainsString(":foreach gpRule in=[{$path} provisioning find where master-configuration=\"{$m}\"] do={", $script);
            }
            $this->assertSame(2, substr_count($script, ':if (!$gpHas) do={ '.$path.' provisioning set $gpRule slave-configurations=($gpList . "cfg-guest") }'));
            // No local named like a property; only the documented provisioning change; whole guarded block in one group
            $this->assertDoesNotMatchRegularExpression('/:local (name|comment|slave-configurations|master-configuration)\b/', $script);
            // Removed only: own rules per CAP no longer selected (by id after the exact key check)
            $this->assertSame(1, preg_match_all('/provisioning (remove|unset)/', $script));
            $this->assertStringContainsString(':if ([:typeof [:find $gpKeep ['.$path.' provisioning get $gpR comment]]] = "nil") do={ '.$path.' provisioning remove $gpR }', $script);

            // Without a selection: one placeholder master (skipped on the router when left unfilled)
            $this->assertStringContainsString('master-configuration="<MASTER_CFG>"', $this->wifi($this->withMode($mode, [])));
        }
    }

    public function test_local_adds_one_virtual_ap_per_master(): void
    {
        $site = $this->withMode('local', ['wifi1', 'wifi2']);
        $script = $this->wifi($site);
        foreach (['wifi1', 'wifi2'] as $m) {
            $key = MikrotikRos7Driver::key('wifi', 'wifi-guest-'.$m);
            $this->assertStringContainsString("/interface wifi add name=\"wifi-guest-{$m}\" master-interface=\"{$m}\" configuration=cfg-guest disabled=no comment=\"{$key}\"", $script);
            $this->assertStringContainsString(":if ([:len [/interface wifi find where name=\"{$m}\"]] = 0) do={ :put", $script);
        }
        $this->assertStringNotContainsString('<WIFI_INTERFACE>', $script);
        $objects = $site->driver()->managedObjects($site)['wifi'];
        $this->assertSame(['path' => '/interface wifi', 'name' => 'wifi-guest-wifi2'], $objects['guest-portal:wifi:wifi-guest-wifi2']);
        $uninstall = $site->driver()->setupCommands($site, [], false)['uninstall']['uninstall_network'];
        $this->assertStringContainsString('/interface wifi remove [find where name="wifi-guest-wifi1" && (comment="guest-portal:wifi:wifi-guest-wifi1"', $uninstall);
        $this->assertStringContainsString('/interface wifi remove [find where name=wifi-guest && (comment="guest-portal:wifi:wifi-guest"', $uninstall);

        $this->assertStringContainsString('master-interface="<WIFI_INTERFACE>"', $this->wifi($this->withMode('local', [])));
    }

    public function test_state_options_and_line_colours_per_master(): void
    {
        $this->nas->forceFill(['router_state' => ['host' => '10.0.1.1', 'menus' => [
            '/interface wifi' => ['rows' => [['name' => 'wifi1', 'master-interface' => ''], ['name' => 'wifi2'],
                ['name' => 'wifi-guest-wifi1', 'master-interface' => 'wifi1', 'configuration' => 'cfg-guest', 'comment' => 'guest-portal:wifi:wifi-guest-wifi1'],
                ['name' => 'virt-iot', 'master-interface' => 'wifi1']]],
            '/interface wifi provisioning' => ['rows' => [['master-configuration' => 'cfg-main', 'slave-configurations' => 'cfg-iot,cfg-guest'],
                ['master-configuration' => 'cfg-office', 'slave-configurations' => '']]],
            '/caps-man provisioning' => ['rows' => [['master-configuration' => 'cfg-legacy', 'slave-configurations' => 'cfg-guest']]],
        ]]])->save();
        $driver = $this->site->driver();
        $this->assertSame(['local' => ['wifi1', 'wifi2'], 'capsman1' => ['cfg-legacy'], 'capsman2' => ['cfg-main', 'cfg-office']],
            $driver->wifiMasterOptions([$this->nas]));

        // CAPsMAN v2: cfg-main has cfg-guest (present), cfg-office not yet (missing)
        $site = $this->withMode('capsman2', ['cfg-main', 'cfg-office']);
        $wifi = $driver->compareState($site, $this->nas->router_state)['wifi'];
        $this->assertSame('present', $wifi['guest-portal:wifi:slave-cfg-main']['status']);
        $this->assertSame('missing', $wifi['guest-portal:wifi:slave-cfg-office']['status']);
        $script = $this->wifi($site);
        $lines = explode("\n", $script);
        $status = $driver->lineStatuses($script, ['guest-portal:wifi:slave-cfg-main' => 'present', 'guest-portal:wifi:slave-cfg-office' => 'missing']);
        $at = fn (string $needle) => array_values(array_unique(array_filter(array_map(fn ($l, $s) => str_contains($l, $needle) ? ($s ?? 'null') : null, $lines, $status))));
        $this->assertSame(['ok'], $at('master-configuration="cfg-main"'));
        $this->assertSame(['missing'], $at('master-configuration="cfg-office"'));
        // Lines inside the foreach group inherit the rule's state
        $inner = array_keys(array_filter($lines, fn ($l) => str_contains($l, ':if (!$gpHas)')));
        $this->assertSame(['ok', 'missing'], array_map(fn ($i) => $status[$i], $inner));

        // Local: own virtual AP per master by key
        $site = $this->withMode('local', ['wifi1', 'wifi2']);
        $wifi = $driver->compareState($site, $this->nas->router_state)['wifi'];
        $this->assertSame('present', $wifi['guest-portal:wifi:wifi-guest-wifi1']['status']);
        $this->assertSame('missing', $wifi['guest-portal:wifi:wifi-guest-wifi2']['status']);
        $script = $this->wifi($site);
        $status = $driver->lineStatuses($script, ['guest-portal:wifi:wifi-guest-wifi1' => 'present', 'guest-portal:wifi:wifi-guest-wifi2' => 'missing']);
        $lines = explode("\n", $script);
        $add = fn (string $m) => $status[array_key_first(array_filter($lines, fn ($l) => str_contains($l, "add name=\"wifi-guest-{$m}\"")))];
        $this->assertSame('ok', $add('wifi1'));
        $this->assertSame('missing', $add('wifi2'));

        // Placeholder suggestions: radios and the provisioning rules' master configurations
        $hints = $driver->placeholderHints($site, [$this->nas])['options'];
        $this->assertSame(['wifi1', 'wifi2'], $hints['WIFI_INTERFACE']);
        $this->assertSame(['cfg-legacy', 'cfg-main', 'cfg-office'], $hints['MASTER_CFG']);
        $this->assertArrayNotHasKey('EXISTING_SLAVES', $hints);
    }

    public function test_uninstall_takes_cfg_guest_out_of_every_rule_before_removing_the_objects(): void
    {
        foreach (['capsman1' => '/caps-man', 'capsman2' => '/interface wifi'] as $mode => $path) {
            $site = $this->withMode($mode, ['cfg-main']);
            $u = $site->driver()->setupCommands($site, [], false)['uninstall']['uninstall_network'];
            $p = "{$path} provisioning";
            // All rules (not only the selected masters), only while the own cfg-guest exists
            $this->assertStringContainsString(":if ([:len [{$path} configuration find where name=cfg-guest && (comment=\"guest-portal:wifi:cfg-guest\"", $u);
            $this->assertStringContainsString(":foreach gpRule in=[{$p} find] do={", $u);
            $this->assertStringContainsString(':if ($gpSlave = "cfg-guest") do={ :set gpHas true } else={', $u);
            $this->assertStringContainsString("} else={ {$p} set \$gpRule slave-configurations=[:pick \$gpList 1 [:len \$gpList]] }", $u);
            $this->assertStringContainsString($mode === 'capsman1' ? "{$p} set \$gpRule slave-configurations=\"\"" : "{$p} unset \$gpRule slave-configurations", $u);
            $this->assertLessThan(strpos($u, "{$path} configuration remove"), strpos($u, ':foreach gpRule'));
            // Locals never named like a property
            preg_match_all('/:local (\w+)/', $u, $m);
            $this->assertSame([], array_filter($m[1], fn ($l) => ! str_starts_with($l, 'gp')));
        }
        $site = $this->withMode('local', ['wifi1']);
        $this->assertStringNotContainsString('provisioning', $site->driver()->setupCommands($site, [], false)['uninstall']['uninstall_network']);
    }

    public function test_local_virtual_ap_of_old_versions_without_comment_gets_an_adopt_command(): void
    {
        $site = $this->withMode('local', ['wifi1', 'wifi2']);
        $state = ['host' => '10.0.1.1', 'menus' => ['/interface wifi' => ['rows' => [['name' => 'wifi1'], ['name' => 'wifi2'],
            ['name' => 'wifi-guest', 'master-interface' => 'wifi1', 'configuration' => 'cfg-guest']]]]];
        $wifi = $site->driver()->compareState($site, $state)['wifi'];
        $this->assertSame('unmanaged', $wifi['guest-portal:wifi:wifi-guest-wifi1']['status']);
        // The key of the old name: the Wi-Fi block then renames it to wifi-guest-wifi1
        $this->assertSame('/interface wifi set [find where name="wifi-guest" && master-interface="wifi1" && !comment] comment="guest-portal:wifi:wifi-guest"',
            $wifi['guest-portal:wifi:wifi-guest-wifi1']['adopt']);
        $this->assertStringContainsString('find where name=wifi-guest && master-interface="wifi1" && (comment="guest-portal:wifi:wifi-guest"', $this->wifi($site));
        $this->assertSame('missing', $wifi['guest-portal:wifi:wifi-guest-wifi2']['status']);
    }

    public function test_wifi_configuration_with_an_inherited_comment_counts_as_without_comment(): void
    {
        // RouterOS reports the datapath's (or security's) comment for a wifi configuration without its own
        $site = $this->withMode('capsman2', ['cfg-main']);
        $menus = fn (string $cfgComment, string $own = '') => ['host' => '10.0.1.1', 'menus' => [
            '/interface wifi datapath' => ['rows' => [['name' => 'dp-guest', 'comment' => 'guest-portal:wifi:dp-guest']]],
            '/interface wifi security' => ['rows' => [['name' => 'sec-guest', 'comment' => 'guest-portal']]],
            '/interface wifi configuration' => ['rows' => [['name' => 'cfg-guest', 'ssid' => 'Guest-WiFi', 'datapath' => 'dp-guest',
                'security' => 'sec-guest'] + ($cfgComment !== '' ? ['comment' => $cfgComment] : [])]],
        ]];
        foreach (['guest-portal:wifi:dp-guest', 'guest-portal', ''] as $inherited) {
            $cfg = $site->driver()->compareState($site, $menus($inherited))['wifi']['guest-portal:wifi:cfg-guest'];
            $this->assertSame('unmanaged', $cfg['status'], $inherited);
            $this->assertSame('/interface wifi configuration set [find where name="cfg-guest" && (!comment'
                .' || comment=[/interface wifi datapath get [find where name="dp-guest"] comment]'
                .' || comment=[/interface wifi security get [find where name="sec-guest"] comment])] comment="guest-portal:wifi:cfg-guest"', $cfg['adopt']);
        }
        // Own key or a foreign own comment: not inherited
        $this->assertSame('present', $site->driver()->compareState($site, $menus('guest-portal:wifi:cfg-guest'))['wifi']['guest-portal:wifi:cfg-guest']['status']);
        $this->assertSame('missing', $site->driver()->compareState($site, $menus('my guest cfg'))['wifi']['guest-portal:wifi:cfg-guest']['status']);
        // The legacy sec-guest itself stays legacy
        $this->assertSame('legacy', $site->driver()->compareState($site, $menus(''))['wifi']['guest-portal:wifi:sec-guest']['status']);
    }

    public function test_form_offers_queried_masters_as_checkboxes_else_free_text(): void
    {
        $admin = User::factory()->admin()->create();
        $page = fn () => $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$this->site->id}/setup")->assertOk()->getContent();
        $this->withMode('capsman2', ['cfg-main']);
        $html = $page();
        $this->assertStringContainsString('name="wifi_masters_text[capsman2]" value="cfg-main"', $html);
        $this->assertStringNotContainsString('name="wifi_masters[capsman2][]"', $html);

        $this->nas->forceFill(['api_host' => '10.0.1.1', 'api_type' => 'rest', 'api_user' => 'portal-ro', 'api_password' => 'Test pass 1',
            'router_state' => ['host' => '10.0.1.1', 'menus' => ['/interface wifi provisioning' => ['rows' => [
                ['master-configuration' => 'cfg-main'], ['master-configuration' => 'cfg-office']]]]]])->save();
        $html = $page();
        $this->assertMatchesRegularExpression('/name="wifi_masters\[capsman2\]\[\]" value="cfg-main"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/name="wifi_masters\[capsman2\]\[\]" value="cfg-office"\s*>/', $html);
    }
}
