<?php

namespace Tests\Feature;

use App\Federation\Federation;
use App\Http\Controllers\Admin\SetupController;
use App\Http\Controllers\Admin\WizardController;
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
        $this->assertStringContainsString(':put "guest-portal: /interface wifi CAPsMAN is not selected; own cap rules and cfg-guest in its rules (if any) were not removed"', $hint);
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

    /** Router state after a switch to mode local: own objects of the earlier CAPsMAN v1 and v2 modes, plus objects of others. */
    private function switchedState(): array
    {
        $k = fn (string $o) => MikrotikRos7Driver::key('wifi', $o);

        return ['menus' => [
            '/interface wifi' => ['rows' => [['name' => 'wifi1', 'master-interface' => ''],
                ['name' => 'wifi-guest-wifi1', 'master-interface' => 'wifi1', 'configuration' => 'cfg-guest', 'comment' => $k('wifi-guest-wifi1')]]],
            '/interface wifi datapath' => ['rows' => [['name' => 'dp-guest', 'comment' => $k('dp-guest')]]],
            '/interface wifi security' => ['rows' => [['name' => 'sec-guest', 'comment' => $k('sec-guest')]]],
            '/interface wifi configuration' => ['rows' => [['name' => 'cfg-guest', 'ssid' => 'Guest-WiFi', 'comment' => $k('cfg-guest')]]],
            '/interface wifi provisioning' => ['rows' => [
                ['.id' => '*1', 'master-configuration' => 'cfg-main', 'slave-configurations' => 'cfg-iot;cfg-guest'],
                ['.id' => '*2', 'master-configuration' => 'cfg-main', 'slave-configurations' => 'cfg-iot;cfg-guest', 'radio-mac' => 'AA:BB:CC:DD:EE:01',
                    'comment' => $k('cap-aabbccddee01')],
                ['.id' => '*3', 'master-configuration' => 'cfg-five', 'slave-configurations' => 'cfg-iot'],
            ]],
            '/caps-man configuration' => ['rows' => [['name' => 'cfg-guest', 'ssid' => 'Guest-WiFi', 'comment' => $k('cfg-guest')]]],
            // Legacy comment: own; a sec-guest with a comment of others: never touched
            '/caps-man datapath' => ['rows' => [['name' => 'dp-guest', 'comment' => 'guest-portal']]],
            '/caps-man security' => ['rows' => [['name' => 'sec-guest', 'comment' => 'mine']]],
            '/caps-man provisioning' => ['rows' => [['.id' => '*7', 'master-configuration' => 'cfg-old', 'slave-configurations' => 'cfg-guest']]],
        ]];
    }

    public function test_switch_from_capsman_to_local_reports_leftovers_and_offers_a_clean_up(): void
    {
        $site = $this->site(['wifi_modes' => ['local']], ['local' => ['wifi1'], 'capsman2' => ['cfg-main']]);
        $driver = $site->driver();
        $state = $this->switchedState();

        // The Wi-Fi block of mode local names both deselected CAPsMAN packages (hint only, no command there)
        $wifi = $this->commands($site)['network']['wifi'];
        foreach (['/caps-man', '/interface wifi'] as $path) {
            $this->assertStringContainsString("# Not selected: {$path} CAPsMAN.", $wifi);
            $this->assertStringContainsString(":put \"guest-portal: {$path} CAPsMAN is not selected; own cap rules and cfg-guest in its rules (if any) were not removed\"", $wifi);
        }
        $this->assertStringNotContainsString('provisioning remove', $wifi);

        // Router state: the leftovers of the earlier modes, never objects of others or of the selected mode
        $compare = $driver->compareState($site, $state)['wifi'];
        $leftover = array_keys(array_filter($compare, fn ($i) => $i['status'] === 'leftover'));
        sort($leftover);
        $this->assertSame([
            'leftover:/caps-man configuration:cfg-guest',
            'leftover:/caps-man datapath:dp-guest',
            'leftover:/caps-man provisioning:slave-*7',
            'leftover:/interface wifi provisioning:guest-portal:wifi:cap-aabbccddee01',
            'leftover:/interface wifi provisioning:slave-*1',
        ], $leftover);
        $this->assertSame(['path' => '/interface wifi provisioning', 'name' => 'cfg-main', 'status' => 'leftover', 'diff' => []],
            $compare['leftover:/interface wifi provisioning:slave-*1']);
        // A leftover never counts as done in the wizard
        $this->assertFalse(WizardController::green(['wifi' => [$compare['leftover:/caps-man datapath:dp-guest']]]));

        // Clean-up per router: same commands as the uninstall, per package in its order
        $clean = $driver->leftoverCommands($site, $state);
        $this->assertNotNull($clean);
        // /caps-man part first (it has no own cap rules), then the /interface wifi part (starts with its cap rules)
        $split = strpos($clean, '# Own provisioning rules of the guest SSID per CAP');
        [$v1, $v2] = [substr($clean, 0, $split), substr($clean, $split)];
        $this->assertStringNotContainsString('/interface wifi', $v1);
        $this->assertStringNotContainsString('/caps-man', $v2);
        $this->assertStringContainsString(':foreach gpRule in=[/caps-man provisioning find] do={', $v1);
        $this->assertStringContainsString('/caps-man provisioning set $gpRule slave-configurations=""', $v1);
        $this->assertLessThan(strpos($v1, '/caps-man configuration remove [find where name=cfg-guest'), strpos($v1, ':foreach gpRule in=[/caps-man provisioning find]'));
        $this->assertStringContainsString('/caps-man datapath remove [find where name=dp-guest && ', $v1);
        // No own cap rules in /caps-man: no removal emitted there
        $this->assertStringNotContainsString('/caps-man provisioning remove', $v1);
        $this->assertStringContainsString(':foreach gpR in=[/interface wifi provisioning find where comment~"^guest-portal:wifi:cap-[0-9a-f]{12}\\$"] do={ /interface wifi provisioning remove $gpR }', $v2);
        $this->assertStringContainsString('/interface wifi provisioning unset $gpRule slave-configurations', $v2);
        $this->assertLessThan(strpos($v2, ':if ([:len [/interface wifi configuration find where name=cfg-guest'), strpos($v2, 'provisioning remove $gpR'));
        // Mode local still uses cfg-guest, sec-guest, dp-guest and its virtual AP: kept
        $this->assertStringNotContainsString('/interface wifi configuration remove', $v2);
        $this->assertStringNotContainsString('/interface wifi remove', $v2);
        $this->assertDoesNotMatchRegularExpression('/^[^#\n]*\bprovision( |$)/m', $clean);

        // Without the earlier modes' objects: nothing to clean up
        $this->assertNull($driver->leftoverCommands($site, ['menus' => ['/interface wifi provisioning' => ['rows' => [['.id' => '*3', 'master-configuration' => 'cfg-five']]]]]));
        // A package missing on the router: no command of its menus
        $state['menus'] = array_merge($state['menus'], array_fill_keys(['/caps-man configuration', '/caps-man datapath', '/caps-man security', '/caps-man provisioning'], ['error' => 'no such command prefix']));
        $this->assertStringNotContainsString('/caps-man', (string) $driver->leftoverCommands($site, $state));
    }

    public function test_switch_away_from_the_wifi_package_removes_its_virtual_aps_and_objects(): void
    {
        $site = $this->site(['wifi_modes' => ['capsman1']], ['capsman1' => ['cfg-old']]);
        $state = $this->switchedState();
        $state['menus']['/interface wifi']['rows'][] = ['name' => 'wifi-guest', 'master-interface' => 'wifi2', 'comment' => 'guest-portal'];
        $state['menus']['/interface wifi']['rows'][] = ['name' => 'wifi-guest-wifi3', 'master-interface' => 'wifi3', 'comment' => 'mine'];
        $compare = $site->driver()->compareState($site, $state)['wifi'];
        foreach (['leftover:/interface wifi:wifi-guest-wifi1', 'leftover:/interface wifi:wifi-guest', 'leftover:/interface wifi configuration:cfg-guest',
            'leftover:/interface wifi datapath:dp-guest', 'leftover:/interface wifi security:sec-guest'] as $id) {
            $this->assertSame('leftover', $compare[$id]['status'] ?? null, $id);
        }
        $this->assertArrayNotHasKey('leftover:/interface wifi:wifi-guest-wifi3', $compare);
        // capsman1 selected: nothing of /caps-man is left over
        $this->assertEmpty(array_filter(array_keys($compare), fn ($id) => str_starts_with($id, 'leftover:/caps-man')));

        $clean = (string) $site->driver()->leftoverCommands($site, $state);
        $this->assertStringNotContainsString('/caps-man', $clean);
        $ap = strpos($clean, '/interface wifi remove [find where name="wifi-guest-wifi1" && (comment="guest-portal:wifi:wifi-guest-wifi1" || ');
        $this->assertNotFalse($ap);
        $this->assertStringContainsString('/interface wifi remove [find where name="wifi-guest" && (comment="guest-portal:wifi:wifi-guest" || ', $clean);
        $this->assertStringNotContainsString('wifi-guest-wifi3', $clean);
        // Rules first (cfg-guest must still exist for the slave removal), then the virtual APs, then the objects
        $this->assertLessThan($ap, strpos($clean, ':foreach gpRule in=[/interface wifi provisioning find] do={'));
        $this->assertLessThan(strpos($clean, '/interface wifi configuration remove [find where name=cfg-guest'), $ap);
        $this->assertStringContainsString('/interface wifi datapath remove [find where name=dp-guest && ', $clean);
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
