<?php

namespace Tests\Feature;

use App\Auth\Social\LoginMethods;
use App\Auth\Social\SocialSettings;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.22.0: switches for the guest login methods in the settings (CONCEPT.md §9, decision 2026-11-03).
 */
class LoginMethodSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_renders_for_admins_only_with_prerequisite_hints(): void
    {
        $admin = User::factory()->admin()->create();

        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee(__('portal.settings.login_methods.title'))
            ->assertSee(__('portal.settings.login_methods.methods.code'))
            ->assertSee(__('portal.settings.login_methods.methods.social'))
            // SMS and approval are on by default but have no provider / channel; social is off (no hint).
            ->assertSee(__('portal.settings.login_methods.hint_sms'))
            ->assertSee(__('portal.settings.login_methods.hint_approval'))
            ->assertDontSee(__('portal.settings.login_methods.hint_social'));

        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->get('/admin/settings')->assertForbidden();
        $this->onZone('admin')->actingAs($manager)->post('/admin/settings/login-methods', ['methods' => ['code' => '1']])->assertForbidden();
        $this->assertSame(LoginMethods::DEFAULTS, LoginMethods::all());
    }

    public function test_hints_follow_the_prerequisites(): void
    {
        $admin = User::factory()->admin()->create();
        Channel::create(['kind' => 'sms', 'driver' => 'clickatell', 'name' => 'SMS', 'enabled' => true, 'config' => ['api_key' => 'k1']]);
        Channel::create(['kind' => 'notification', 'driver' => 'webhook', 'name' => 'Hook', 'enabled' => true,
            'config' => ['url' => 'https://hooks.example.com/in', 'secret' => 'hook-secret']]);
        LoginMethods::save([LoginMethods::SOCIAL => true]);

        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertDontSee(__('portal.settings.login_methods.hint_sms'))
            ->assertDontSee(__('portal.settings.login_methods.hint_approval'))
            ->assertSee(__('portal.settings.login_methods.hint_social'));

        SocialSettings::save(['providers' => ['github' => ['enabled' => true, 'client_id' => 'gh-client']]], ['github' => 'gh-test-secret']);
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertDontSee(__('portal.settings.login_methods.hint_social'));
    }

    public function test_switches_are_saved_and_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->onZone('admin')->actingAs($admin)->from('/admin/settings')->post('/admin/settings/login-methods', ['methods' => [
            'code' => '1', 'sms' => '0', 'approval' => '0', 'social' => '1',
        ]])->assertRedirect('/admin/settings')->assertSessionHasNoErrors()->assertSessionHas('status', __('portal.saved'));

        $this->assertSame(['code' => true, 'sms' => false, 'approval' => false, 'social' => true], LoginMethods::all());
        $this->assertSame(['enabled' => ['code', 'social'], 'disabled' => ['sms', 'approval']],
            AuditLog::where('action', 'settings.login_methods')->sole()->data);

        // Missing fields (unchecked boxes without hidden input) count as off.
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/login-methods', ['methods' => ['sms' => '1']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['code' => false, 'sms' => true, 'approval' => false, 'social' => false], LoginMethods::all());
    }

    public function test_at_least_one_method_stays_on(): void
    {
        $admin = User::factory()->admin()->create();

        $this->onZone('admin')->actingAs($admin)->from('/admin/settings')->post('/admin/settings/login-methods', ['methods' => [
            'code' => '0', 'sms' => '0', 'approval' => '0', 'social' => '0',
        ]])->assertRedirect('/admin/settings')->assertSessionHasErrors(['methods' => __('portal.settings.login_methods.at_least_one')]);

        $this->assertSame(LoginMethods::DEFAULTS, LoginMethods::all());
        $this->assertSame(0, AuditLog::where('action', 'settings.login_methods')->count());
    }
}
