<?php

namespace Tests\Feature;

use App\Models\Grant;
use App\Models\NasClient;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCodesAndSitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_sites_and_routers_and_clients_file_is_rendered(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create();

        $this->onZone('admin')->actingAs($manager)->get('/admin/sites')->assertForbidden();

        $this->onZone('admin')->actingAs($admin)->post('/admin/sites', [
            'name' => 'Site A', 'slug' => 'site-a', 'driver' => 'mikrotik_ros7', 'radius_address' => '10.0.0.10',
        ])->assertRedirect();
        $site = Site::sole();

        $this->onZone('admin')->actingAs($admin)->post("/admin/sites/{$site->id}/routers", [
            'name' => 'Router A', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799,
        ])->assertRedirect();
        $nas = NasClient::sole();

        $clients = file_get_contents(config('portal.radius.clients_file'));
        $this->assertStringContainsString("client nas-{$nas->id} {", $clients);
        $this->assertStringContainsString('secret = "'.$nas->radius_secret.'"', $clients);

        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$site->id}/routers/{$nas->id}/config")
            ->assertOk()->assertSee('/radius incoming')->assertSee('add address=10.0.0.10', false);
        $this->onZone('admin')->actingAs($admin)->get("/admin/sites/{$site->id}/login.html")
            ->assertOk()->assertSee('portal?site=site-a', false)->assertSee('$(link-login-only-esc)', false);
    }

    public function test_manager_creates_edits_and_prints_codes(): void
    {
        $manager = User::factory()->create();

        $this->onZone('admin')->actingAs($manager)->post('/admin/grants', [
            'quantity' => 2, 'duration' => 'custom', 'custom_hours' => 2, 'expiry_mode' => 'first_login', 'label' => 'Room 1',
        ])->assertRedirectContains('/admin/grants/print');

        $grants = Grant::all();
        $this->assertCount(2, $grants);
        $this->assertSame(7200, $grants[0]->duration_s);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $grants[0]->code);
        $this->assertNotSame($grants[0]->code, $grants[1]->code);

        $grant = $grants[0];
        $this->onZone('admin')->actingAs($manager)->post("/admin/grants/{$grant->id}/extend", ['hours' => 1])->assertRedirect();
        $this->assertSame(10800, $grant->fresh()->duration_s);

        $this->onZone('admin')->actingAs($manager)->get('/admin/grants?status=unused')->assertOk()->assertSee($grant->code);
        $this->onZone('admin')->actingAs($manager)->get("/admin/grants/{$grant->id}/edit")->assertOk();
        $this->onZone('admin')->actingAs($manager)->get('/admin/grants/print?ids[]='.$grant->id)->assertOk()->assertSee($grant->code);

        $this->onZone('admin')->actingAs($manager)->post("/admin/grants/{$grant->id}/revoke")->assertRedirect();
        $this->assertNotNull($grant->fresh()->revoked_at);
    }
}
