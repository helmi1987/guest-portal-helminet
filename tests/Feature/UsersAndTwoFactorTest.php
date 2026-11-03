<?php

namespace Tests\Feature;

use App\Auth\TwoFactor;
use App\Enums\Role;
use App\Livewire\Admin\Login;
use App\Models\Device;
use App\Models\Guest;
use App\Models\Setting;
use App\Models\User;
use App\Services\GrantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M6: user management (roles, last admin) and TOTP two-factor login.
 */
class UsersAndTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_users_and_manager_is_kept_out(): void
    {
        $admin = User::factory()->admin()->create();
        $admin2 = User::factory()->admin()->create();
        $manager = User::factory()->create();

        $this->onZone('admin')->actingAs($manager)->get('/admin/users')->assertForbidden();
        $this->onZone('admin')->actingAs($manager)->get('/admin/audit')->assertForbidden();
        $this->onZone('admin')->actingAs($manager)->get('/admin/profile')->assertOk();

        $this->onZone('admin')->actingAs($admin)->post('/admin/users', ['name' => 'New', 'email' => 'new@example.com',
            'role' => 'manager', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->onZone('admin')->actingAs($admin)->post('/admin/users', ['name' => 'New', 'email' => 'new@example.com',
            'role' => 'manager', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
            ->assertRedirect('/admin/users');
        $new = User::where('email', 'new@example.com')->sole();
        $this->assertSame(Role::Manager, $new->role);
        $this->onZone('admin')->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('new@example.com');
        $this->onZone('admin')->actingAs($admin)->get('/admin/users/create')->assertOk();
        $this->onZone('admin')->actingAs($admin)->get("/admin/users/{$new->id}/edit")->assertOk()->assertSee(__('portal.delete'));

        // Edit without password keeps it; role change works.
        $this->onZone('admin')->actingAs($admin)->put("/admin/users/{$new->id}", ['name' => 'Renamed',
            'email' => 'new@example.com', 'role' => 'admin'])->assertRedirect('/admin/users');
        $this->assertSame(Role::Admin, $new->fresh()->role);
        $this->assertTrue(password_verify('a-long-password', $new->fresh()->password));

        // Not yourself.
        $this->onZone('admin')->actingAs($admin)->put("/admin/users/{$admin->id}", ['name' => $admin->name,
            'email' => $admin->email, 'role' => 'manager'])->assertSessionHasErrors('role');
        $this->onZone('admin')->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertSessionHasErrors('user');

        // Others can be removed until only one admin is left.
        $this->onZone('admin')->actingAs($admin)->delete("/admin/users/{$new->id}")->assertRedirect('/admin/users');
        $this->assertSoftDeleted($new);
        $this->onZone('admin')->actingAs($admin)->delete("/admin/users/{$admin2->id}")->assertRedirect('/admin/users');
        $admin2->restore();
        $admin->forceFill(['role' => Role::Manager])->save();
        $this->onZone('admin')->actingAs($admin2)->put("/admin/users/{$admin2->id}", ['name' => 'x',
            'email' => $admin2->email, 'role' => 'manager'])->assertSessionHasErrors('role');
    }

    public function test_manager_sees_masked_phone_numbers(): void
    {
        $grant = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => 'first_login']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest', 'phone_e164' => '+41790000067']);
        Device::create(['grant_id' => $grant->id, 'guest_id' => $guest->id, 'mac' => 'AA:BB:CC:DD:EE:FF', 'bound_at' => now()]);

        $this->onZone('admin')->actingAs(User::factory()->create())->get("/admin/grants/{$grant->id}/edit")
            ->assertOk()->assertSee('+4179*****67')->assertDontSee('+41790000067');
        $this->onZone('admin')->actingAs(User::factory()->admin()->create())->get("/admin/grants/{$grant->id}/edit")
            ->assertOk()->assertSee('+41790000067');
    }

    public function test_enrol_login_with_totp_and_recovery_code_then_disable(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $twoFactor = app(TwoFactor::class);

        $this->onZone('admin')->actingAs($user)->post('/admin/profile/two-factor')->assertRedirect('/admin/profile');
        $secret = $user->fresh()->totp_secret;
        $this->onZone('admin')->actingAs($user)->get('/admin/profile')->assertOk()->assertSee('<svg', false);
        $this->onZone('admin')->actingAs($user)->post('/admin/profile/two-factor/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $response = $this->onZone('admin')->actingAs($user)->post('/admin/profile/two-factor/confirm', ['code' => $twoFactor->currentCode($secret)]);
        $codes = $response->assertSessionHas('recovery_codes')->getSession()->get('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertNotContains($codes[0], $user->fresh()->recovery_codes);
        auth()->logout();

        // Password alone does not log in.
        $login = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'correct-horse-battery')
            ->call('login')->assertSet('twoFactor', true)->assertNoRedirect();
        $this->assertGuest();
        // The code used for enrolment cannot be replayed.
        $login->set('code', $twoFactor->currentCode($secret))->call('verify')->assertHasErrors('code');
        $login->set('code', strtoupper($codes[0]))->call('verify')->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertCount(7, $user->fresh()->recovery_codes);
        auth()->logout();

        // A recovery code is single-use; a fresh TOTP step works.
        $login = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'correct-horse-battery')->call('login');
        $login->set('code', $codes[0])->call('verify')->assertHasErrors('code');
        $user->fresh()->forceFill(['totp_last_step' => null])->save();
        $login->set('code', $twoFactor->currentCode($secret))->call('verify')->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);

        // Disable needs password + code.
        $this->onZone('admin')->actingAs($user)->delete('/admin/profile/two-factor', ['current_password' => 'wrong', 'code' => $codes[1]])
            ->assertSessionHasErrors('current_password');
        $this->onZone('admin')->actingAs($user)->delete('/admin/profile/two-factor', ['current_password' => 'correct-horse-battery', 'code' => $codes[1]])
            ->assertRedirect('/admin/profile');
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertNull($user->fresh()->totp_secret);
    }

    public function test_code_failures_restart_login_and_ip_is_throttled(): void
    {
        // The throttle message contains the remaining seconds: keep them from crossing a second boundary.
        $this->freezeTime();
        $twoFactor = app(TwoFactor::class);
        // Five failures discard the pending login: the password is needed again, the account is not locked.
        $users = [$this->userWithTwoFactor(), $this->userWithTwoFactor(), $this->userWithTwoFactor()];
        foreach (array_slice($users, 0, 2) as $user) {
            $login = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login');
            for ($i = 0; $i < 4; $i++) {
                $login->set('code', '000000')->call('verify')->assertHasErrors(['code' => __('portal.auth.two_factor_failed')]);
            }
            $login->set('code', '000000')->call('verify')->assertHasErrors(['email' => __('portal.auth.two_factor_restart')])
                ->assertSet('twoFactor', false);
            $login->set('code', $twoFactor->currentCode($user->totp_secret))->call('verify')->assertHasErrors('email');
            $this->assertGuest();
        }
        $this->assertTrue(Livewire::test(Login::class)->set('email', $users[0]->email)->set('password', 'password')
            ->call('login')->get('twoFactor'));

        // Ten failures from one IP across users block the code step for this IP.
        Livewire::test(Login::class)->set('email', $users[2]->email)->set('password', 'password')->call('login')
            ->set('code', $twoFactor->currentCode($users[2]->totp_secret))->call('verify')
            ->assertHasErrors(['code' => __('portal.auth.throttle', ['seconds' => 300])]);
        $this->assertGuest();
    }

    public function test_code_failures_are_limited_per_user_across_ips_and_restarts(): void
    {
        $this->freezeTime();
        $twoFactor = app(TwoFactor::class);
        $user = $this->userWithTwoFactor();
        $other = $this->userWithTwoFactor();
        $fresh = fn () => Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login');
        // Two rounds of five failures, each from another IP (IP limiter cleared) and restarted with the password.
        for ($round = 0; $round < 2; $round++) {
            RateLimiter::clear('admin-2fa-ip|127.0.0.1');
            $login = $fresh();
            for ($i = 0; $i < 4; $i++) {
                $login->set('code', '000000')->call('verify')->assertHasErrors(['code' => __('portal.auth.two_factor_failed')]);
            }
            $login->set('code', '000000')->call('verify')->assertHasErrors(['email' => __('portal.auth.two_factor_restart')]);
        }

        // Even the correct code from a fresh IP is refused now, with the generic throttle message.
        RateLimiter::clear('admin-2fa-ip|127.0.0.1');
        $fresh()->set('code', $twoFactor->currentCode($user->totp_secret))->call('verify')
            ->assertHasErrors(['code' => __('portal.auth.throttle', ['seconds' => 900])]);
        $this->assertGuest();

        // Other users are not affected; after 15 minutes the user can sign in again.
        Livewire::test(Login::class)->set('email', $other->email)->set('password', 'password')->call('login')
            ->set('code', $twoFactor->currentCode($other->totp_secret))->call('verify')->assertHasNoErrors();
        $this->assertAuthenticatedAs($other);
        auth()->logout();
        $this->travel(16)->minutes();
        $fresh()->set('code', $twoFactor->currentCode($user->totp_secret))->call('verify')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_no_remember_cookie_and_security_changes_end_other_sessions(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery', 'remember_token' => 'old-token']);
        Livewire::test(Login::class)->assertNotSet('remember', true)
            ->set('email', $user->email)->set('password', 'correct-horse-battery')->call('login')
            ->assertRedirect(route('admin.dashboard'));
        $this->assertSame('old-token', $user->fresh()->remember_token);
        $this->assertFalse(property_exists(Login::class, 'remember'));

        $session = fn (string $id) => DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id,
            'payload' => '', 'last_activity' => time()]);
        $session('other-device');
        $this->onZone('admin')->actingAs($user)->post('/admin/profile/password', ['current_password' => 'correct-horse-battery',
            'password' => 'another-long-password', 'password_confirmation' => 'another-long-password'])->assertRedirect();
        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
        $this->assertNotSame('old-token', $user->fresh()->remember_token);

        $admin = User::factory()->admin()->create(['password' => 'admin-long-password']);
        $twoFactorUser = $this->userWithTwoFactor();
        $token = $twoFactorUser->remember_token;
        DB::table('sessions')->insert(['id' => 'tf-device', 'user_id' => $twoFactorUser->id, 'payload' => '', 'last_activity' => time()]);
        $this->artisan('portal:admin', ['--reset-2fa' => $twoFactorUser->email])->assertSuccessful();
        $this->assertSame(0, DB::table('sessions')->where('id', 'tf-device')->count());
        $this->assertNotSame($token, $twoFactorUser->fresh()->remember_token);

        $session('before-delete');
        $this->onZone('admin')->actingAs($admin)->delete("/admin/users/{$user->id}")->assertRedirect('/admin/users');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_totp_step_and_recovery_code_cannot_be_used_twice_by_stale_models(): void
    {
        $twoFactor = app(TwoFactor::class);
        $user = $this->userWithTwoFactor();
        $codes = $twoFactor->regenerateRecoveryCodes($user);
        $a = User::find($user->id);
        $b = User::find($user->id);

        $code = $twoFactor->currentCode($user->totp_secret);
        $this->assertTrue($twoFactor->verify($a, $code));
        $this->assertFalse($twoFactor->verify($b, $code));

        $this->assertTrue($twoFactor->useRecoveryCode($a, $codes[0]));
        $this->assertFalse($twoFactor->useRecoveryCode($b, $codes[0]));
        $this->assertCount(7, $user->fresh()->recovery_codes);
        $this->assertTrue($twoFactor->useRecoveryCode($b, $codes[1]));
        $this->assertCount(6, $user->fresh()->recovery_codes);
    }

    public function test_admin_resets_other_users_two_factor_with_own_password_only(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'admin-long-password']);
        app(TwoFactor::class)->begin($admin);
        $admin->forceFill(['totp_confirmed_at' => now()])->save();
        $other = $this->userWithTwoFactor();

        $this->onZone('admin')->actingAs($admin)->post("/admin/users/{$admin->id}/reset-two-factor",
            ['current_password' => 'admin-long-password'])->assertForbidden();
        $this->onZone('admin')->actingAs($admin)->post("/admin/users/{$other->id}/reset-two-factor",
            ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertTrue($other->fresh()->hasTwoFactor());
        $this->onZone('admin')->actingAs($admin)->post("/admin/users/{$other->id}/reset-two-factor",
            ['current_password' => 'admin-long-password'])->assertRedirect();
        $this->assertFalse($other->fresh()->hasTwoFactor());
    }

    public function test_required_setting_sends_users_without_2fa_to_profile(): void
    {
        $user = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($user)->get('/admin/grants')->assertOk();

        Setting::put(TwoFactor::REQUIRED_SETTING, true);
        $this->onZone('admin')->actingAs($user)->get('/admin/grants')->assertRedirect('/admin/profile');
        $this->onZone('admin')->actingAs($user)->get('/admin/settings')->assertRedirect('/admin/profile');
        $this->onZone('admin')->actingAs($user)->get('/admin/profile')->assertOk();

        $enrolled = $this->userWithTwoFactor();
        $this->onZone('admin')->actingAs($enrolled)->get('/admin/grants')->assertOk();
    }

    public function test_cli_resets_two_factor(): void
    {
        $user = $this->userWithTwoFactor();
        $this->artisan('portal:admin', ['--reset-2fa' => $user->email])->assertSuccessful();
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->artisan('portal:admin', ['--reset-2fa' => 'nobody@example.com'])->assertFailed();
    }

    private function userWithTwoFactor(): User
    {
        $user = User::factory()->create();
        app(TwoFactor::class)->begin($user);
        $user->forceFill(['totp_confirmed_at' => now()])->save();

        return $user->fresh();
    }
}
