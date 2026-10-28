<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\Admin\Login;
use App\Models\User;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_redirects_guests_to_login(): void
    {
        $this->onZone('admin')->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_user_can_log_in(): void
    {
        $user = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');
        $this->assertGuest();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-horse-battery')
            ->call('login')
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_roles_gate_admin_only_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create();

        $this->onZone('admin')->actingAs($manager)->get('/admin')->assertOk()->assertSee('Manager');
        $this->onZone('admin')->actingAs($manager)->get('/admin/settings')->assertForbidden();
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk();
    }

    public function test_admin_command_creates_federation_ready_user(): void
    {
        $this->artisan('portal:admin', [
            '--email' => 'admin@example.com',
            '--name' => 'Admin',
            '--password' => 'a-long-test-password',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame(26, strlen($user->id));
        $this->assertSame(app(LocalInstance::class)->id(), $user->origin_instance_id);
        $this->assertNotEmpty($user->version);

        $user->delete();
        $this->assertSoftDeleted($user);
    }
}
