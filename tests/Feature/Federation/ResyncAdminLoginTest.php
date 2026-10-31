<?php

namespace Tests\Feature\Federation;

use App\Auth\TwoFactor;
use App\Federation\Outbox;
use App\Federation\Resync;
use App\Livewire\Admin\Login;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * While the restore resync blocks replicated writes, admins can still log in and out (audit entries go
 * to the application log, no row and no event), while other admin writes still answer 503.
 */
class ResyncAdminLoginTest extends TestCase
{
    use RefreshDatabase;

    private function ownEvents(): int
    {
        return DB::table(Outbox::TABLE)->where('origin', app(LocalInstance::class)->id())->count();
    }

    public function test_audit_goes_to_the_log_during_the_resync(): void
    {
        $user = User::factory()->admin()->create();
        Resync::mark('test');
        $events = $this->ownEvents();
        Log::spy();

        $this->assertNull(Audit::log('auth.login', $user, ['method' => 'password', 'password' => 'x'], $user));

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame($events, $this->ownEvents());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'resync')
            && $context['action'] === 'auth.login' && $context['actor'] === $user->name
            && $context['user_id'] === $user->id && ! isset($context['data']['password']))->once();

        Resync::clear('test');
        $this->assertInstanceOf(AuditLog::class, Audit::log('auth.login', $user, [], $user));
    }

    public function test_password_login_and_logout_work_during_the_resync(): void
    {
        $user = User::factory()->admin()->create(['password' => 'correct-horse-battery']);
        Resync::mark('test');
        $events = $this->ownEvents();

        // A failed login is audited (log only) and answered normally.
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'wrong-password')
            ->call('login')->assertHasErrors('email');
        $this->assertGuest();

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'correct-horse-battery')
            ->call('login')->assertHasNoErrors()->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);

        // The admin UI (federation page included) is reachable; replicated writes still answer 503.
        $this->onZone('admin')->actingAs($user)->get('/admin')->assertOk();
        $this->onZone('admin')->actingAs($user)->get('/admin/federation')->assertOk();
        $this->onZone('admin')->actingAs($user)->post('/admin/users', ['name' => 'New', 'email' => 'new@example.com',
            'role' => 'manager', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
            ->assertStatus(503)->assertSee(__('portal.http_errors.503.title'));
        $this->assertSame(0, User::query()->where('email', 'new@example.com')->count());

        $this->onZone('admin')->actingAs($user)->post('/admin/logout')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame($events, $this->ownEvents());
    }

    public function test_two_factor_login_during_the_resync_without_replay(): void
    {
        $twoFactor = app(TwoFactor::class);
        $user = User::factory()->admin()->create(['password' => 'correct-horse-battery']);
        $twoFactor->begin($user);
        $codes = $twoFactor->confirm($user->fresh(), $twoFactor->currentCode($user->fresh()->totp_secret));
        $user = $user->fresh();
        // Enrolment used the current step: start before it, so the current code is accepted below.
        $user->forceFill(['totp_last_step' => null])->save();
        $secret = $user->totp_secret;
        Resync::mark('test');
        $events = $this->ownEvents();

        $login = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'correct-horse-battery')
            ->call('login')->assertSet('twoFactor', true);
        // Wrong code: audited to the log, normal error.
        $login->set('code', '000000')->call('verify')->assertHasErrors(['code' => __('portal.auth.two_factor_failed')]);
        // Recovery codes cannot be consumed now: own message, code stays valid.
        $login->set('code', $codes[0])->call('verify')
            ->assertHasErrors(['code' => __('portal.auth.two_factor_recovery_resync')]);
        $this->assertCount(8, $user->fresh()->recovery_codes);
        $this->assertGuest();

        // One code for every attempt below: a step change in between must not make a replay pass.
        $code = $twoFactor->currentCode($secret);
        $login->set('code', $code)->call('verify')->assertHasNoErrors()
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->totp_last_step);
        $this->assertSame($events, $this->ownEvents());
        $this->onZone('admin')->post('/admin/logout')->assertRedirect(route('login'));
        $this->assertGuest();

        // The step used during the resync cannot be replayed, neither now nor after the resync.
        $login = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'correct-horse-battery')->call('login');
        $login->set('code', $code)->call('verify')->assertHasErrors('code');
        Resync::clear('test');
        $login->set('code', $code)->call('verify')->assertHasErrors('code');
        $this->assertGuest();

        // After the resync recovery codes work again.
        $login->set('code', $codes[0])->call('verify')->assertRedirect(route('admin.dashboard'));
        $this->assertCount(7, $user->fresh()->recovery_codes);
    }
}
