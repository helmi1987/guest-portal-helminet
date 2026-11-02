<?php

namespace Tests\Feature;

use App\Federation\Federation;
use App\Http\Controllers\Admin\SetupController;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use App\Nas\MikrotikRos7Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wi-Fi modes freely combinable, guest SSID per CAP ("variant A") and the provisioning editor
 * (CONCEPT.md §9, decisions 2026-11-02).
 */
class WifiModesAndCapsTest extends TestCase
{
    use RefreshDatabase;

    private const NETWORK = ['vlan_id' => 30, 'bridge' => 'bridge1', 'interface' => 'vlan-guest', 'subnet' => '10.30.0.0/24',
        'gateway' => '10.30.0.1', 'pool' => '10.30.0.10-10.30.0.250', 'ssid' => 'Guest-WiFi'];

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

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function save(array $data): TestResponse
    {
        return $this->onZone('admin')->actingAs($this->admin())->post("/admin/sites/{$this->site->id}/setup", $data + self::NETWORK);
    }

    private function site(array $setup, array $masters = [], array $caps = []): Site
    {
        $this->site->forceFill(['settings' => array_merge($this->site->settings, ['setup' => $setup + self::NETWORK]),
            'wifi_masters_local' => $masters, 'wifi_caps_local' => $caps])->save();

        return Site::with('nasClients')->find($this->site->id);
    }

    private function commands(Site $site): array
    {
        return $site->driver()->setupCommands($site, [], false);
    }

    public function test_modes_are_read_from_the_list_or_the_single_value_of_earlier_versions(): void
    {
        $this->assertSame(['capsman2'], $this->site(['wifi_mode' => 'capsman2'])->wifiModes());
        $this->assertSame(['local'], $this->site(['wifi_mode' => 'local'])->wifiModes());
        $this->assertSame(['capsman1', 'local'], $this->site(['wifi_modes' => ['local', 'capsman1', 'x'], 'wifi_mode' => 'capsman2'])->wifiModes());
        $this->assertSame([Site::DEFAULT_WIFI_MODE], $this->site([])->wifiModes());
        $this->assertSame([Site::DEFAULT_WIFI_MODE], $this->site(['wifi_modes' => ['bogus']])->wifiModes());
    }

    public function test_saving_stores_the_list_in_order_and_the_first_mode_for_older_instances(): void
    {
        $this->save(['wifi_modes' => ['local', 'capsman1']])->assertSessionHasNoErrors();
        $setup = $this->site->fresh()->setting('setup');
        $this->assertSame(['capsman1', 'local'], $setup['wifi_modes']);
        $this->assertSame('capsman1', $setup['wifi_mode']);
        // Form of earlier versions (single field)
        $this->save(['wifi_mode' => 'local'])->assertSessionHasNoErrors();
        $this->assertSame(['local'], $this->site->fresh()->wifiModes());
        // Invalid
        $this->save(['wifi_modes' => ['capsman9']])->assertSessionHasErrors('wifi_modes.0');
        $this->save(['wifi_modes' => []])->assertSessionHasErrors('wifi_modes');
        $this->save(['wifi_modes' => ['local', 'local']])->assertSessionHasErrors('wifi_modes.0');
    }

    public function test_all_modes_generate_one_part_each_and_shared_objects_once_per_package(): void
    {
        $site = $this->site(['wifi_modes' => Site::WIFI_MODES], ['capsman1' => ['cfg-main'], 'capsman2' => ['cfg-five'], 'local' => ['wifi1']]);
        $wifi = $this->commands($site)['network']['wifi'];
        foreach (['/caps-man', '/interface wifi'] as $path) {
            foreach (['datapath' => 'dp-guest', 'security' => 'sec-guest', 'configuration' => 'cfg-guest'] as $menu => $name) {
                $this->assertSame(1, substr_count($wifi, "    {$path} {$menu} add name={$name} "), "{$path} {$menu}");
            }
        }
        $this->assertStringContainsString('/caps-man manager set enabled=yes', $wifi);
        $this->assertStringContainsString('/interface wifi capsman set enabled=yes', $wifi);
        $this->assertStringContainsString('/caps-man provisioning find where master-configuration="cfg-main"', $wifi);
        $this->assertStringContainsString('/interface wifi provisioning find where master-configuration="cfg-five"', $wifi);
        $this->assertStringContainsString('/interface wifi add name="wifi-guest-wifi1" master-interface="wifi1"', $wifi);
        // One password guard and one group around everything (password stored: masked)
        $site->forceFill(['wifi_password_local' => 'Guest pass 1', 'settings' => array_merge($site->settings, ['setup' => $site->setting('setup')
            + ['wifi_security' => 'wpa', 'wifi_password_shared' => false]])])->save();
        $masked = $this->commands($site->fresh())['network']['wifi'];
        $this->assertSame(1, substr_count($masked, ':error "replace <WIFI_PASSWORD> first"'));
        $this->assertStringEndsWith("\n}", $masked);
        $this->assertSame(substr_count($masked, '{'), substr_count($masked, '}'));
        // Never provisions
        $this->assertDoesNotMatchRegularExpression('/^[^#\n]*\bprovision( |$)/m', $masked);
        $this->assertStringContainsString('provision that CAP manually', $masked);

        // Uninstall: both packages, own CAP rules and cfg-guest out of the rules before the objects
        $u = $this->commands($site->fresh())['uninstall']['uninstall_network'];
        $this->assertLessThan(strpos($u, '/caps-man configuration remove'), strpos($u, '/caps-man provisioning remove $gpR'));
        $this->assertLessThan(strpos($u, '/interface wifi configuration remove'), strpos($u, '/interface wifi remove [find where name="wifi-guest-wifi1"'));
        $this->assertSame(2, substr_count($u, 'datapath remove [find where name=dp-guest'));

        // State ids: the /caps-man objects next to the wifi package carry the key separately
        $objects = $site->driver()->managedObjects($site)['wifi'];
        $this->assertSame(['path' => '/caps-man datapath', 'name' => 'dp-guest', 'key' => 'guest-portal:wifi:dp-guest'], $objects['guest-portal:wifi:dp-guest@caps-man']);
        $this->assertSame('/interface wifi datapath', $objects['guest-portal:wifi:dp-guest']['path']);
        // Trunk port of the wifi package CAPs
        $this->assertStringContainsString('<TRUNK_PORT_TO_CAP>', $this->commands($site)['network']['vlan']);
    }

    public function test_single_mode_keeps_the_block_of_earlier_versions(): void
    {
        $wifi = $this->commands($this->site(['wifi_mode' => 'capsman1'], ['capsman1' => ['cfg-main']]))['network']['wifi'];
        $this->assertStringStartsWith('# Legacy CAPsMAN (/caps-man, wireless package)', $wifi);
        // Only the hint about the other, not selected CAPsMAN package names it (no command, nothing removed there)
        [$block, $hint] = explode('# Not selected: /interface wifi CAPsMAN.', $wifi, 2);
        $this->assertStringNotContainsString('/interface wifi', $block);
        $this->assertStringNotContainsString('provisioning remove', $hint);
        $this->assertStringContainsString(':put "guest-portal: /interface wifi is not selected; own cap rules there (if any) were not removed"', $hint);
        $objects = $this->site(['wifi_mode' => 'capsman1'])->driver()->managedObjects($this->site->fresh())['wifi'];
        $this->assertSame(['path' => '/caps-man datapath', 'name' => 'dp-guest'], $objects['guest-portal:wifi:dp-guest']);
    }

    public function test_state_and_line_colours_cover_both_packages(): void
    {
        $site = $this->site(['wifi_modes' => ['capsman1', 'capsman2']], ['capsman1' => ['cfg-main'], 'capsman2' => ['cfg-main']]);
        $menus = ['/caps-man datapath' => ['rows' => [['name' => 'dp-guest', 'bridge' => 'bridge1', 'vlan-id' => '30', 'comment' => 'guest-portal:wifi:dp-guest']]],
            '/interface wifi datapath' => ['rows' => []],
            '/caps-man provisioning' => ['rows' => [['.id' => '*1', 'master-configuration' => 'cfg-main', 'slave-configurations' => 'cfg-guest']]],
            '/interface wifi provisioning' => ['rows' => [['.id' => '*2', 'master-configuration' => 'cfg-main', 'slave-configurations' => '']]]];
        $compare = $site->driver()->compareState($site, ['menus' => $menus]);
        $this->assertSame('present', $compare['wifi']['guest-portal:wifi:dp-guest@caps-man']['status']);
        $this->assertSame('missing', $compare['wifi']['guest-portal:wifi:dp-guest']['status']);
        $this->assertSame('present', $compare['wifi']['guest-portal:wifi:slave-cfg-main@caps-man']['status']);
        $this->assertSame('missing', $compare['wifi']['guest-portal:wifi:slave-cfg-main']['status']);
        // Lines by comment key: the worse of both packages
        $status = SetupController::statusByKey([$compare]);
        $this->assertSame('missing', $status['guest-portal:wifi:dp-guest']);
        $this->assertSame('missing', $status['guest-portal:wifi:slave-cfg-main']);
    }

    public function test_cap_selection_is_saved_locally_normalized_and_validated(): void
    {
        $this->save(['wifi_modes' => ['capsman2'], 'wifi_caps' => ['capsman2' => ['aa:bb:cc:dd:ee:01']],
            'wifi_caps_text' => ['capsman2' => 'AA-BB-CC-DD-EE-02, aabbccddee01']])->assertSessionHasNoErrors();
        $site = $this->site->fresh();
        $this->assertSame(['AA:BB:CC:DD:EE:01', 'AA:BB:CC:DD:EE:02'], $site->wifiCaps('capsman2'));
        $this->assertSame([], $site->wifiCaps('capsman1'));
        $this->assertContains('wifi_caps_local', Federation::localColumns('sites'));
        $this->assertArrayNotHasKey('wifi_caps', (array) $site->setting('setup'));
        $this->save(['wifi_modes' => ['capsman2'], 'wifi_caps_text' => ['capsman2' => 'AA:BB:CC:DD:EE']])->assertSessionHasErrors('wifi_caps_text.capsman2');
        $this->assertSame(['AA:BB:CC:DD:EE:01', 'AA:BB:CC:DD:EE:02'], $this->site->fresh()->wifiCaps('capsman2'));
    }

    public function test_cap_selection_generates_own_rules_and_takes_cfg_guest_out_of_the_others(): void
    {
        foreach (['capsman1' => ['/caps-man', 'hw-supported-modes', '/caps-man interface'], 'capsman2' => ['/interface wifi', 'supported-bands', '/interface wifi']] as $mode => [$path, $band, $ifMenu]) {
            $site = $this->site(['wifi_modes' => [$mode]], [$mode => ['cfg-main']], [$mode => ['AA:BB:CC:DD:EE:01']]);
            $wifi = $this->commands($site)['network']['wifi'];
            $p = "{$path} provisioning";
            $key = 'guest-portal:wifi:cap-aabbccddee01';
            // Radio's current configuration, the admin's rule, own rule before it with the copied values plus cfg-guest
            $this->assertStringContainsString(":foreach gpIf in=[{$ifMenu} find where radio-mac=AA:BB:CC:DD:EE:01] do={", $wifi);
            $this->assertStringContainsString("{$p} add place-before=\$gpRule action=[{$p} get \$gpRule action] radio-mac=AA:BB:CC:DD:EE:01 master-configuration=\$gpCfg slave-configurations=\$gpSlaves comment=\"{$key}\"", $wifi);
            $this->assertStringContainsString(':set gpSlaves ($gpSlaves . "cfg-guest")', $wifi);
            $this->assertStringContainsString("{$p} set \$gpOwn {$band}=[{$p} get \$gpRule {$band}]", $wifi);
            // A disabled rule of the admin is never the one copied
            $this->assertStringContainsString(":if ([:len \$gpRule] = 0 && [:tostr [{$p} get \$gpR disabled]] != \"true\" && ", $wifi);
            $this->assertStringContainsString("{$p} move \$gpOwn destination=\$gpRule", $wifi);
            // Masters not used: no append; cfg-guest out of every rule except the own ones
            $this->assertStringNotContainsString('master-configuration="cfg-main"', $wifi);
            $this->assertStringContainsString(":foreach gpRule in=[{$p} find where !(comment~\"^guest-portal:wifi:cap-[0-9a-f]{12}\\\$\")] do={", $wifi);
            $this->assertStringContainsString(":local gpKeep {\"{$key}\"}", $wifi);
            $this->assertDoesNotMatchRegularExpression('/:local (name|comment|radio-mac|master-configuration|slave-configurations|configuration)\b/', $wifi);
            $this->assertDoesNotMatchRegularExpression('/^[^#\n]*\bprovision( |$)/m', $wifi);

            $objects = $site->driver()->managedObjects($site)['wifi'];
            $this->assertSame(['path' => $p, 'name' => null], $objects[$key]);
            $state = ['menus' => [$p => ['rows' => [['.id' => '*5', 'radio-mac' => 'AA:BB:CC:DD:EE:01', 'slave-configurations' => 'cfg-iot', 'comment' => $key],
                ['.id' => '*6', 'master-configuration' => 'cfg-main', 'slave-configurations' => 'cfg-iot']]]]];
            $compare = $site->driver()->compareState($site, $state)['wifi'];
            $this->assertSame('differs', $compare[$key]['status']);
            $this->assertArrayNotHasKey(MikrotikRos7Driver::slaveKey('cfg-main'), $compare);
            $state['menus'][$p]['rows'][0]['slave-configurations'] = 'cfg-iot,cfg-guest';
            $this->assertSame('present', $site->driver()->compareState($site, $state)['wifi'][$key]['status']);
            // Lines of the own rule are coloured by its key
            $lines = $site->driver()->lineStatuses($wifi, [$key => 'missing']);
            $this->assertContains('missing', $lines);
            // Without a selection the own rules of earlier selections are removed (keep list empty)
            $this->assertStringContainsString(':local gpKeep [:toarray ""]', $this->commands($this->site(['wifi_modes' => [$mode]], [$mode => ['cfg-main']]))['network']['wifi']);
        }
    }

    public function test_references_to_deleted_slave_configurations_are_never_copied(): void
    {
        // A deleted slave configuration shows as "*<id>" in slave-configurations: the append, the per-CAP copy and the
        // removal skip it, so it is neither set again (the set would fail) nor carried into an own rule
        $skip = '[:pick $gpSlave 0 1] != "*"';
        foreach (['capsman1' => '/caps-man', 'capsman2' => '/interface wifi'] as $mode => $path) {
            $append = $this->commands($this->site(['wifi_modes' => [$mode]], [$mode => ['cfg-main']]));
            $caps = $this->commands($this->site(['wifi_modes' => [$mode]], [$mode => ['cfg-main']], [$mode => ['AA:BB:CC:DD:EE:01']]));
            $loops = [
                'append' => [$append['network']['wifi'], ":if ([:len \$gpSlave] > 0 && {$skip}) do={ :set gpList (\$gpList . \$gpSlave . \",\") }"],
                'cap rule' => [$caps['network']['wifi'], ":if ([:len \$gpSlave] > 0 && \$gpSlave != \"cfg-guest\" && {$skip}) do={ :set gpSlaves"],
                'per-CAP removal' => [$caps['network']['wifi'], ":if ([:len \$gpSlave] > 0 && {$skip}) do={ :set gpList (\$gpList . \",\" . \$gpSlave) }"],
                'uninstall' => [$append['uninstall']['uninstall_network'], ":if ([:len \$gpSlave] > 0 && {$skip}) do={ :set gpList (\$gpList . \",\" . \$gpSlave) }"],
            ];
            foreach ($loops as $name => [$script, $needle]) {
                $this->assertStringContainsString($needle, $script, "{$mode} {$name}");
                // Every slave loop of the script has the skip
                $this->assertSame(substr_count($script, ':foreach gpSlave in='), substr_count($script, $skip), "{$mode} {$name}");
            }
        }
    }

    public function test_radio_choices_and_master_options_skip_own_rules(): void
    {
        $this->nas->forceFill(['api_host' => '10.0.1.1', 'api_type' => 'rest', 'api_user' => 'portal-ro', 'api_password' => 'Test pass 1',
            'router_state' => ['host' => '10.0.1.1', 'menus' => [
                '/interface wifi radio' => ['rows' => [['radio-mac' => 'AA:BB:CC:DD:EE:02', 'cap' => 'cap-hall', 'bands' => '5ghz-ac;5ghz-ax'],
                    ['radio-mac' => 'AA:BB:CC:DD:EE:09', 'cap' => '']]],
                '/interface wifi' => ['rows' => [['name' => 'cap-hall-1', 'radio-mac' => 'AA:BB:CC:DD:EE:02', 'configuration' => 'cfg-main']]],
                '/caps-man radio' => ['rows' => [['radio-mac' => 'AA:BB:CC:DD:EE:03', 'remote-cap-identity' => 'old-cap', 'interface' => 'cap1']]],
                '/caps-man interface' => ['rows' => [['name' => 'cap1', 'radio-mac' => 'AA:BB:CC:DD:EE:03', 'master-interface' => 'none', 'configuration' => 'cfg-v1']]],
                '/interface wifi provisioning' => ['rows' => [['master-configuration' => 'cfg-main'], ['master-configuration' => 'cfg-x', 'comment' => 'guest-portal:wifi:cap-aabbccddee02']]],
            ]]])->save();
        $driver = $this->site->driver();
        $options = $driver->wifiCapOptions([$this->nas]);
        $this->assertSame([['mac' => 'AA:BB:CC:DD:EE:02', 'label' => 'cap-hall 5ghz-ac,5ghz-ax', 'configuration' => 'cfg-main']], $options['capsman2']);
        $this->assertSame([['mac' => 'AA:BB:CC:DD:EE:03', 'label' => 'old-cap cap1', 'configuration' => 'cfg-v1']], $options['capsman1']);
        $this->assertSame(['cfg-main'], $driver->wifiMasterOptions([$this->nas])['capsman2']);

        $this->site(['wifi_modes' => ['capsman2']]);
        $html = $this->onZone('admin')->actingAs($this->admin())->get("/admin/sites/{$this->site->id}/setup")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="wifi_caps\[capsman2\]\[\]" value="AA:BB:CC:DD:EE:02"\s*>/', $html);
        $this->assertStringContainsString('data-wifi-caps="capsman1"', $html);
    }

    public function test_derived_modes_list_every_mode_with_own_objects_but_never_replace_saved_ones(): void
    {
        $driver = $this->site->driver();
        $state = ['menus' => [
            '/caps-man configuration' => ['rows' => [['name' => 'cfg-guest', 'ssid' => 'G', 'comment' => 'guest-portal:wifi:cfg-guest']]],
            '/interface wifi configuration' => ['rows' => [['name' => 'cfg-guest', 'ssid' => 'G', 'comment' => 'guest-portal:wifi:cfg-guest']]],
            '/interface wifi capsman' => ['rows' => [['enabled' => 'true']]],
        ]];
        $this->site->forceFill(['settings' => array_merge($this->site->settings, ['setup' => []])])->save();
        $this->assertSame([['capsman1', 'capsman2'], 'own'], $driver->deriveSetup($this->site->fresh(), $state)['wifi_modes']);
        $this->assertArrayNotHasKey('wifi_modes', $driver->deriveSetup($this->site(['wifi_mode' => 'local']), $state));
    }
}
