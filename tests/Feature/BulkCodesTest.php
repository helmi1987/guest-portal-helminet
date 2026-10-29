<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\Site;
use App\Models\User;
use App\Services\VoucherQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk codes, voucher sheet with Wi-Fi QR and CSV export (CONCEPT.md §8, §9).
 */
class BulkCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_create_writes_one_audit_entry_and_prints_a_sheet_with_wifi_qr(): void
    {
        $site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['setup' => ['ssid' => 'Guest WiFi']]]);
        $manager = User::factory()->create();

        $response = $this->onZone('admin')->actingAs($manager)->post('/admin/grants', [
            'quantity' => 12, 'duration' => '1d', 'expiry_mode' => 'first_login', 'label' => 'Event', 'site_ids' => [$site->id],
        ])->assertRedirectContains('/admin/grants/print');

        $grants = Grant::all();
        $this->assertCount(12, $grants);
        $this->assertCount(12, $grants->pluck('code')->unique());

        $audit = AuditLog::sole();
        $this->assertSame('grant.bulk_created', $audit->action);
        $this->assertSame(12, $audit->data['count']);
        $this->assertStringNotContainsString($grants[0]->code, json_encode($audit->data));

        $this->onZone('admin')->actingAs($manager)->get($response->headers->get('Location'))->assertOk()
            ->assertSee($grants[0]->code)->assertSee($grants[11]->code)->assertSee('Guest WiFi')->assertSee('<svg', false)
            ->assertDontSee('Export CSV');

        $this->assertSame('WIFI:S:Guest WiFi;T:nopass;;', VoucherQr::wifiPayload('Guest WiFi'));
        $this->assertSame('WIFI:S:"CAFE01";T:nopass;;', VoucherQr::wifiPayload('CAFE01'));
        $this->assertSame('WIFI:S:a\\;b;T:nopass;;', VoucherQr::wifiPayload('a;b'));
        $this->onZone('admin')->actingAs($manager)->post('/admin/grants', [
            'quantity' => 101, 'duration' => '1d', 'expiry_mode' => 'first_login',
        ])->assertSessionHasErrors('quantity');
    }

    public function test_wifi_qr_only_when_the_ssid_is_unambiguous(): void
    {
        Site::create(['slug' => 'a', 'name' => 'A', 'driver' => 'mikrotik_ros7', 'settings' => ['setup' => ['ssid' => 'Guest A']]]);
        $b = Site::create(['slug' => 'b', 'name' => 'B', 'driver' => 'mikrotik_ros7', 'settings' => ['setup' => ['ssid' => 'Guest B']]]);
        $manager = User::factory()->create();

        $this->onZone('admin')->actingAs($manager)->post('/admin/grants', ['quantity' => 1, 'duration' => '8h', 'expiry_mode' => 'first_login']);
        $this->onZone('admin')->actingAs($manager)->post('/admin/grants', ['quantity' => 1, 'duration' => '8h', 'expiry_mode' => 'first_login', 'site_ids' => [$b->id]]);
        [$all, $onlyB] = Grant::query()->orderBy('created_at')->orderBy('id')->get();

        $qr = new VoucherQr;
        $this->assertNull($qr->ssidFor($all->load('siteLinks')));
        $this->assertSame('Guest B', $qr->ssidFor($onlyB->load('siteLinks')));
        $this->onZone('admin')->actingAs($manager)->get("/admin/grants/{$onlyB->id}/edit")->assertOk()->assertSee('Guest B')->assertSee('<svg', false);
    }

    public function test_csv_export_is_admin_only_and_neutralises_formulas(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($admin)->post('/admin/grants', [
            'quantity' => 3, 'duration' => '8h', 'expiry_mode' => 'first_login', 'label' => '=HYPERLINK("x")',
        ]);
        Grant::query()->first()->update(['label' => " \n@SUM(1)"]);
        $ids = Grant::query()->pluck('id')->all();
        $url = '/admin/grants/export?'.http_build_query(['ids' => $ids]);

        $this->onZone('admin')->actingAs($manager)->get($url)->assertForbidden();

        $csv = $this->onZone('admin')->actingAs($admin)->get($url)->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(5, $lines); // header, 3 rows, one label with a quoted line break
        $this->assertStringStartsWith('code,label,status', $lines[0]);
        $this->assertStringContainsString(Grant::query()->first()->code, $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("\"' \n@SUM(1)\"", $csv);
        $this->assertSame('grant.exported', AuditLog::latest('id')->value('action'));
    }
}
