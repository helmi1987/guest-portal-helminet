<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Provisioning editor (CONCEPT.md §9, decision 2026-11-02): rules from the stored router state, guarded
 * script, add-if-missing, never stored, admin only.
 */
class ProvisioningEditorTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private NasClient $nas;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://portal.example.com']);
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot-a.guest.example.com'], 'radius_address' => '10.0.0.10',
                'setup' => ['wifi_modes' => ['capsman2']]]]);
        $this->nas = NasClient::find($this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.1.1', 'coa_port' => 3799])->id);
        $this->nas->forceFill(['api_host' => '10.0.1.1', 'api_type' => 'rest', 'api_user' => 'portal-ro', 'api_password' => 'Test pass 1',
            'router_state' => ['host' => '10.0.1.1', 'menus' => [
                '/interface wifi configuration' => ['rows' => [['name' => 'cfg-main'], ['name' => 'cfg-iot'], ['name' => 'cfg-five'], ['name' => 'cfg-guest']]],
                '/interface wifi provisioning' => ['rows' => [
                    ['.id' => '*1', 'master-configuration' => 'cfg-main', 'slave-configurations' => 'cfg-iot', 'supported-bands' => '2ghz-n;2ghz-ax'],
                    ['.id' => '*2', 'master-configuration' => 'cfg-five', 'slave-configurations' => ''],
                    ['.id' => '*3', 'master-configuration' => 'cfg-main', 'slave-configurations' => 'cfg-guest', 'radio-mac' => 'AA:BB:CC:DD:EE:01',
                        'comment' => 'guest-portal:wifi:cap-aabbccddee01'],
                ]],
                '/caps-man provisioning' => ['error' => 'no such command'],
            ]]])->save();
    }

    private function script(array $input): string
    {
        return $this->site->driver()->provisioningScript($this->nas->fresh(), $input);
    }

    public function test_rules_are_read_from_the_state_per_package(): void
    {
        $rules = $this->site->driver()->provisioningRules($this->nas);
        $this->assertSame(['capsman2'], array_keys($rules));
        $this->assertSame(['cfg-five', 'cfg-guest', 'cfg-iot', 'cfg-main'], $rules['capsman2']['configurations']);
        $this->assertSame(['id' => '*1', 'band' => '2ghz-n,2ghz-ax', 'master' => 'cfg-main', 'slaves' => ['cfg-iot'], 'radio_mac' => null,
            'identity' => '', 'disabled' => false, 'comment' => '', 'own' => false, 'editable' => true], $rules['capsman2']['rules'][0]);
        $this->assertTrue($rules['capsman2']['rules'][2]['own']);
    }

    public function test_changed_rules_are_set_by_id_only_while_the_router_has_the_read_values(): void
    {
        $s = $this->script(['mode' => 'capsman2', 'rules' => ['*1' => ['master' => 'cfg-main', 'slaves' => ['cfg-iot', 'cfg-guest']],
            '*2' => ['master' => 'cfg-five', 'slaves' => []]]]);
        $p = '/interface wifi provisioning';
        $this->assertStringContainsString(":if ([:len [{$p} find where .id=*1]] = 0) do={", $s);
        $this->assertStringContainsString(":if ([:tostr [{$p} get *1 master-configuration]] = \"cfg-main\" && \$gpList = \",cfg-iot\") do={", $s);
        $this->assertStringContainsString("{$p} set *1 master-configuration=cfg-main slave-configurations=cfg-iot,cfg-guest", $s);
        $this->assertStringContainsString('was changed on the router after the state query, skipped', $s);
        // Unchanged rule: nothing
        $this->assertStringNotContainsString('*2', $s);
        $this->assertStringContainsString("{$p} print", $s);
        $this->assertStringContainsString('provision that CAP manually', $s);
        $this->assertDoesNotMatchRegularExpression('/^[^#\n]*\bprovision( |$)/m', $s);
        $this->assertDoesNotMatchRegularExpression('/:local (name|comment|master-configuration|slave-configurations|supported-bands)\b/', $s);

        // Empty slaves: unset (wifi package)
        $s = $this->script(['mode' => 'capsman2', 'rules' => ['*1' => ['master' => 'cfg-five', 'slaves' => []]]]);
        $this->assertStringContainsString("{$p} set *1 master-configuration=cfg-five\n        {$p} unset *1 slave-configurations", $s);
    }

    public function test_references_to_deleted_configurations_are_dropped(): void
    {
        // RouterOS shows a deleted slave configuration as its internal id ("*A")
        $state = $this->nas->router_state;
        $state['menus']['/interface wifi provisioning']['rows'][0]['slave-configurations'] = 'cfg-iot;*A';
        $this->nas->forceFill(['router_state' => $state])->save();
        $rule = $this->site->driver()->provisioningRules($this->nas->fresh())['capsman2']['rules'][0];
        $this->assertSame(['cfg-iot'], $rule['slaves']);
        $this->assertTrue($rule['editable']);

        $s = $this->script(['mode' => 'capsman2', 'rules' => ['*1' => ['master' => 'cfg-main', 'slaves' => ['cfg-iot', 'cfg-guest']]]]);
        $p = '/interface wifi provisioning';
        // The router-side comparison skips them as well, the set drops them
        $this->assertStringContainsString(":foreach gpSlave in=[{$p} get *1 slave-configurations] do={ :if ([:len \$gpSlave] > 0 && [:pick \$gpSlave 0 1] != \"*\") do={", $s);
        $this->assertStringContainsString('$gpList = ",cfg-iot")', $s);
        $this->assertStringContainsString("{$p} set *1 master-configuration=cfg-main slave-configurations=cfg-iot,cfg-guest", $s);
        $this->assertStringNotContainsString('*A', $s);
    }

    public function test_new_rules_are_added_only_when_missing_and_without_comment(): void
    {
        $s = $this->script(['mode' => 'capsman2', 'new' => [['band' => '5ghz', 'master' => 'cfg-five', 'slaves' => ['cfg-guest']]]]);
        $this->assertStringContainsString('/interface wifi provisioning find where master-configuration="cfg-five"', $s);
        $this->assertStringContainsString(':foreach gpWant in={"5ghz-a";"5ghz-n";"5ghz-ac";"5ghz-ax"} do={', $s);
        $this->assertStringContainsString('/interface wifi provisioning add action=create-dynamic-enabled master-configuration=cfg-five slave-configurations=cfg-guest supported-bands=5ghz-a,5ghz-n,5ghz-ac,5ghz-ax', $s);
        $this->assertStringNotContainsString('comment=', $s);
    }

    public function test_invalid_input_is_refused(): void
    {
        $bad = [
            ['mode' => 'capsman1'],
            ['mode' => 'capsman2', 'rules' => ['*9' => ['master' => 'cfg-main']]],
            ['mode' => 'capsman2', 'rules' => ['*3' => ['master' => 'cfg-main']]],
            ['mode' => 'capsman2', 'rules' => ['*1' => ['master' => 'cfg-unknown']]],
            ['mode' => 'capsman2', 'rules' => ['*1' => ['master' => 'cfg-guest']]],
            ['mode' => 'capsman2', 'rules' => ['*1' => ['master' => 'cfg-main', 'slaves' => ['x"; /system reset-configuration']]]],
            ['mode' => 'capsman2', 'new' => [['band' => '7ghz', 'master' => 'cfg-main']]],
            ['mode' => 'capsman2', 'new' => [['band' => '5ghz', 'master' => '']]],
        ];
        foreach ($bad as $i => $input) {
            try {
                $this->script($input);
                $this->fail("accepted: {$i}");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_endpoint_is_admin_only_json_not_cached_and_stores_nothing(): void
    {
        $url = "/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/provisioning";
        $body = ['mode' => 'capsman2', 'rules' => ['*1' => ['master' => 'cfg-five', 'slaves' => ['cfg-iot']]]];
        $before = $this->nas->fresh()->router_state;
        $audit = AuditLog::count();
        $r = $this->onZone('admin')->actingAs(User::factory()->admin()->create())->postJson($url, $body)->assertOk();
        $this->assertStringContainsString('set *1 master-configuration=cfg-five slave-configurations=cfg-iot', $r->json('script'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertSame($before, $this->nas->fresh()->router_state);
        $this->assertSame($audit, AuditLog::count());
        $this->onZone('admin')->actingAs(User::factory()->admin()->create())->postJson($url, ['mode' => 'capsman2', 'rules' => ['*1' => ['master' => 'nope']]])
            ->assertStatus(422);
        $this->onZone('admin')->actingAs(User::factory()->create(['role' => 'manager']))->postJson($url, $body)->assertForbidden();

        // The setup page shows the editor of the router
        $html = $this->onZone('admin')->actingAs(User::factory()->admin()->create())->get("/admin/sites/{$this->site->id}/setup")->assertOk()->getContent();
        $this->assertStringContainsString('data-provisioning-editor="'.$this->nas->id.'"', $html);
        $this->assertStringContainsString('data-provisioning-package="capsman2"', $html);
    }

    public function test_rules_with_unsafe_router_values_are_not_editable(): void
    {
        $state = $this->nas->router_state;
        $state['menus']['/interface wifi provisioning']['rows'] = [
            ['.id' => '*1', 'master-configuration' => 'cfg-main"; /x', 'slave-configurations' => ''],
            ['.id' => '*2', 'master-configuration' => 'cfg-main', 'slave-configurations' => "cfg-iot\n/system reset-configuration"],
            ['.id' => '*3', 'master-configuration' => 'cfg-main', 'supported-bands' => "2ghz-n\r\n/x"],
            ['.id' => '*4', 'master-configuration' => 'cfg-main', 'slave-configurations' => 'cfg-iot', 'supported-bands' => '5ghz-ax'],
        ];
        $this->nas->forceFill(['router_state' => $state])->save();
        $rules = collect($this->site->driver()->provisioningRules($this->nas->fresh())['capsman2']['rules'])->keyBy('id');
        $this->assertSame([false, false, false, true], [$rules['*1']['editable'], $rules['*2']['editable'], $rules['*3']['editable'], $rules['*4']['editable']]);
        foreach (['*1', '*2', '*3'] as $id) {
            try {
                $this->script(['mode' => 'capsman2', 'rules' => [$id => ['master' => 'cfg-five', 'slaves' => []]]]);
                $this->fail("accepted {$id}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey("rules.{$id}", $e->errors());
            }
        }
        $s = $this->script(['mode' => 'capsman2', 'rules' => ['*4' => ['master' => 'cfg-five', 'slaves' => []]]]);
        foreach (explode("\n", $s) as $line) {
            if (str_starts_with($line, '#')) {
                $this->assertStringNotContainsString("\r", $line);
            }
        }
    }

    public function test_new_rule_check_reports_a_disabled_rule_with_the_same_band_and_master(): void
    {
        $s = $this->script(['mode' => 'capsman2', 'new' => [['band' => '5ghz', 'master' => 'cfg-five', 'slaves' => []]]]);
        $p = '/interface wifi provisioning';
        $this->assertStringContainsString(":if ([:tostr [{$p} get \$gpR disabled]] = \"true\") do={ :set gpDisabled true } else={ :set gpFound true }", $s);
        $this->assertStringContainsString('exists but is disabled, not added', $s);
        $this->assertStringContainsString('and master cfg-five exists, not added', $s);
        $this->assertSame(substr_count($s, '{'), substr_count($s, '}'));
    }

    public function test_array_input_is_a_422_not_a_500(): void
    {
        $url = "/admin/sites/{$this->site->id}/setup/routers/{$this->nas->id}/provisioning";
        $bad = [
            ['mode' => ['capsman2']],
            ['mode' => 'capsman2', 'rules' => ['*1' => ['master' => ['cfg-main']]]],
            ['mode' => 'capsman2', 'rules' => ['*1' => ['master' => 'cfg-main', 'slaves' => [['x']]]]],
            ['mode' => 'capsman2', 'rules' => ['*1' => 'cfg-main']],
            ['mode' => 'capsman2', 'new' => [['band' => ['5ghz'], 'master' => 'cfg-main']]],
            ['mode' => 'capsman2', 'new' => [['band' => '5ghz', 'master' => ['cfg-main']]]],
            ['mode' => 'capsman2', 'new' => 'x'],
            ['mode' => 'capsman2', 'rules' => 'x'],
        ];
        $admin = User::factory()->admin()->create();
        foreach ($bad as $i => $body) {
            $this->onZone('admin')->actingAs($admin)->postJson($url, $body)->assertStatus(422, "case {$i}");
        }
        // The driver itself is robust against the same input
        $this->expectException(ValidationException::class);
        $this->script(['mode' => ['capsman2']]);
    }
}
