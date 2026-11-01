<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\VoucherQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Toolbox Wi-Fi QR code (CONCEPT.md §9): admin only, nothing stored, no-store response.
 */
class ToolboxWifiQrTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret-pass;42';

    private function admin(): static
    {
        return $this->onZone('admin')->actingAs(User::factory()->admin()->create());
    }

    private function qrFor(string $payload): string
    {
        return (new VoucherQr)->svg($payload, 240);
    }

    private function form(array $overrides = []): array
    {
        return array_merge(['ssid' => 'Office WiFi', 'encryption' => 'WPA', 'wifi_password' => self::PASSWORD,
            'hidden' => '0', 'show_password' => '1', 'format' => 'a4'], $overrides);
    }

    public function test_admin_opens_index_and_form_and_sees_nav_entry(): void
    {
        $this->admin()->get('/admin/toolbox')->assertOk()
            ->assertSee(route('admin.toolbox.wifi-qr'), false)
            ->assertSee(__('portal.nav.toolbox'));
        $this->get('/admin/toolbox/wifi-qr')->assertOk()->assertSee('name="wifi_password"', false);
    }

    public function test_admin_prints_wpa_qr_no_store_and_nothing_audited(): void
    {
        $response = $this->admin()->post('/admin/toolbox/wifi-qr', $this->form())->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertSee('Office WiFi')->assertSee(self::PASSWORD)
            ->assertSee($this->qrFor('WIFI:T:WPA;S:Office WiFi;P:Secret-pass\;42;;'), false);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_password_text_can_be_omitted_and_a6_format(): void
    {
        $this->admin()->post('/admin/toolbox/wifi-qr', $this->form(['show_password' => '0', 'format' => 'a6']))
            ->assertOk()->assertDontSee(self::PASSWORD)->assertSee('105mm')
            ->assertSee($this->qrFor(VoucherQr::wifiPayload('Office WiFi', self::PASSWORD)), false);
    }

    public function test_open_network(): void
    {
        $this->admin()->post('/admin/toolbox/wifi-qr', $this->form(['encryption' => 'nopass']))
            ->assertOk()->assertDontSee(self::PASSWORD)
            ->assertSee($this->qrFor('WIFI:S:Office WiFi;T:nopass;;'), false);
    }

    public function test_hidden_network(): void
    {
        $this->admin()->post('/admin/toolbox/wifi-qr', $this->form(['hidden' => '1']))
            ->assertOk()->assertSee($this->qrFor('WIFI:T:WPA;S:Office WiFi;P:Secret-pass\;42;H:true;;'), false);
    }

    public function test_validation_error_does_not_echo_password(): void
    {
        $this->admin()->from('/admin/toolbox/wifi-qr')
            ->post('/admin/toolbox/wifi-qr', $this->form(['ssid' => str_repeat('x', 33)]))
            ->assertRedirect('/admin/toolbox/wifi-qr')->assertSessionHasErrors('ssid');

        $this->assertNull(session()->getOldInput('wifi_password'));
        $this->get('/admin/toolbox/wifi-qr')->assertOk()
            ->assertSee('value="'.str_repeat('x', 33).'"', false)
            ->assertDontSee(self::PASSWORD)->assertDontSee('value="'.self::PASSWORD.'"', false);
    }

    public function test_wpa_requires_password_of_at_least_8_characters_and_ssid_max_32_bytes(): void
    {
        $this->admin()->post('/admin/toolbox/wifi-qr', $this->form(['wifi_password' => '']))->assertSessionHasErrors('wifi_password');
        $this->post('/admin/toolbox/wifi-qr', $this->form(['wifi_password' => 'short']))->assertSessionHasErrors('wifi_password');
        $this->post('/admin/toolbox/wifi-qr', $this->form(['wifi_password' => str_repeat('a', 64)]))->assertSessionHasErrors('wifi_password');
        // 11 x "ä" = 22 bytes passes, 17 x "ä" = 34 bytes fails
        $this->post('/admin/toolbox/wifi-qr', $this->form(['ssid' => str_repeat('ä', 11)]))->assertOk();
        $this->post('/admin/toolbox/wifi-qr', $this->form(['ssid' => str_repeat('ä', 17)]))->assertSessionHasErrors('ssid');
    }

    public function test_manager_and_guest_are_denied(): void
    {
        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->get('/admin/toolbox')->assertForbidden();
        $this->get('/admin/toolbox/wifi-qr')->assertForbidden();
        $this->post('/admin/toolbox/wifi-qr', $this->form())->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee(route('admin.toolbox.index'), false);
    }

    public function test_unauthenticated_is_redirected_to_login(): void
    {
        $this->onZone('admin')->get('/admin/toolbox')->assertRedirect(route('login'));
        $this->post('/admin/toolbox/wifi-qr', $this->form())->assertRedirect(route('login'));
    }
}
