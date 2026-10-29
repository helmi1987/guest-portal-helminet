<?php

namespace Tests\Feature;

use App\Approval\ApprovalService;
use App\Enums\ApprovalStatus;
use App\Livewire\Admin\Login;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\Site;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M6: audit log entries, admin page and retention purge.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_actions_and_logins_are_audited(): void
    {
        $manager = User::factory()->create(['password' => 'correct-horse-battery']);

        Livewire::test(Login::class)->set('email', $manager->email)->set('password', 'wrong-password')->call('login');
        Livewire::test(Login::class)->set('email', $manager->email)->set('password', 'correct-horse-battery')->call('login');
        $this->assertSame(1, AuditLog::where('action', 'auth.login_failed')->where('user_id', $manager->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'auth.login')->count());
        $this->assertStringNotContainsString('correct-horse', AuditLog::query()->pluck('data')->toJson());

        $this->onZone('admin')->actingAs($manager)->post('/admin/grants', [
            'quantity' => 1, 'duration' => 'custom', 'custom_hours' => 2, 'expiry_mode' => 'first_login',
        ]);
        $grant = Grant::sole();
        $this->onZone('admin')->actingAs($manager)->put("/admin/grants/{$grant->id}", ['label' => 'Room 2']);
        $this->onZone('admin')->actingAs($manager)->post("/admin/grants/{$grant->id}/extend", ['hours' => 1]);
        $this->onZone('admin')->actingAs($manager)->post("/admin/grants/{$grant->id}/revoke");

        $entries = AuditLog::where('subject_type', 'grant')->where('subject_id', $grant->id)->orderBy('id')->get();
        $this->assertSame(['grant.created', 'grant.updated', 'grant.extended', 'grant.revoked'], $entries->pluck('action')->all());
        $this->assertSame($manager->id, $entries[0]->user_id);
        $this->assertSame($manager->name, $entries[0]->actor);
        $this->assertSame('127.0.0.1', $entries[0]->ip);
        $this->assertArrayNotHasKey('code', $entries[0]->data);
        $this->assertSame(['label'], $entries[1]->data['changed']);
    }

    public function test_approval_decisions_record_channel_actor(): void
    {
        $site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']);
        $guest = Guest::create(['first_name' => 'Test', 'last_name' => 'Guest']);
        $request = ApprovalRequest::create(['guest_id' => $guest->id, 'site_id' => $site->id, 'mac' => 'AA:BB:CC:DD:EE:FF',
            'status' => ApprovalStatus::Pending, 'expires_at' => now()->addMinutes(10)]);

        $this->assertTrue(app(ApprovalService::class)->decide($request, 3600, 'Telegram User', 'telegram'));

        $log = AuditLog::where('action', 'approval.approved')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame('Telegram User', $log->actor);
        $this->assertSame('telegram', $log->data['via']);
        $this->assertSame($request->id, $log->subject_id);
    }

    public function test_secrets_are_stripped_and_page_filters_and_purge(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $log = Audit::log('settings.updated', null, ['password' => 'x', 'nested' => ['bot_token' => 'y', 'ok' => 1], 'keys' => ['a']]);
        $this->assertSame(['nested' => ['ok' => 1], 'keys' => ['a']], $log->data);
        Audit::log('grant.revoked', null, ['n' => 1]);
        for ($i = 0; $i < 55; $i++) {
            Audit::log('site.updated');
        }

        $this->onZone('admin')->get('/admin/audit?group=settings')->assertOk()
            ->assertSee('settings.updated')->assertDontSee('grant.revoked');
        $this->onZone('admin')->get('/admin/audit?user='.$admin->id)->assertOk()->assertSee(__('portal.audit.page', ['page' => 1, 'pages' => 2]));
        $this->onZone('admin')->get('/admin/audit?group=nope')->assertSessionHasErrors('group');

        AuditLog::query()->where('action', 'grant.revoked')->update(['created_at' => now()->subDays(400)]);
        $this->artisan('portal:purge')->assertSuccessful();
        $this->assertSame(0, AuditLog::where('action', 'grant.revoked')->count());
        $this->assertSame(1, AuditLog::where('action', 'settings.updated')->count());
    }
}
