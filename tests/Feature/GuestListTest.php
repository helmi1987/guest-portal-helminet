<?php

namespace Tests\Feature;

use App\Enums\GrantType;
use App\Jobs\DisconnectSession;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\OtpChallenge;
use App\Models\RadiusAccounting;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\GrantManager;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Guests & sessions list (CONCEPT.md §9): methods, terms, sessions, filters, masking, actions.
 */
class GuestListTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:01';

    private Site $site;

    private Grant $codeGrant;

    private SessionSummary $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']);
        $other = Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7']);
        $nas = $this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
        $terms = TermsVersion::publish('en', 'Be nice.');
        $grants = app(GrantManager::class);

        // Code guest, online with accounting.
        $this->codeGrant = $grants->createCode(['duration_s' => 86400, 'expiry_mode' => 'first_login']);
        $this->codeGrant->update(['first_login_at' => now(), 'expires_at' => now()->addDay()]);
        $alice = $this->guest('Alice', 'Example', $this->site, $this->codeGrant, $terms);
        $this->codeGrant->devices()->create(['guest_id' => $alice->id, 'mac' => self::MAC, 'bound_at' => now()]);
        $this->session = SessionSummary::create(['grant_id' => $this->codeGrant->id, 'site_id' => $this->site->id,
            'serving_instance_id' => app(LocalInstance::class)->id(), 'mac' => self::MAC, 'ip' => '10.0.0.50', 'started_at' => now()]);
        RadiusAccounting::create(['session_summary_id' => $this->session->id, 'nas_client_id' => $nas->id,
            'acct_session_id' => 's1', 'username' => 'gtest', 'bytes_in' => 2_000_000, 'bytes_out' => 30_000_000]);

        // SMS guest at the other site (phone masked for managers).
        $smsGrant = $grants->createTimedGrant(GrantType::Sms, $other, 3600, 'SMS');
        $bob = $this->guest('Bob', 'Sample', $other, $smsGrant, null, '+41790001122');
        OtpChallenge::create(['guest_id' => $bob->id, 'site_id' => $other->id, 'mac' => 'AA:BB:CC:00:00:02',
            'phone_e164' => '+41790001122', 'code_hash' => str_repeat('0', 64), 'expires_at' => now()]);

        // Pending approval: no grant yet.
        $carol = $this->guest('Carol', 'Waiting', $this->site, null, $terms);
        ApprovalRequest::create(['guest_id' => $carol->id, 'site_id' => $this->site->id, 'mac' => 'AA:BB:CC:00:00:03',
            'expires_at' => now()->addMinutes(15)]);
    }

    public function test_list_shows_guests_with_method_terms_sessions_and_masks_phone_for_managers(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create();

        $this->onZone('admin')->actingAs($admin)->get('/admin/guests')->assertOk()
            ->assertSeeInOrder(['Waiting, Carol', 'Approval'])
            ->assertSee('Example, Alice')->assertSee($this->codeGrant->code)->assertSee('EN v1')
            ->assertSee(self::MAC)->assertSee('10.0.0.50')->assertSee('30.0 MB')->assertSee('Disconnect')
            ->assertSee('+41790001122');

        $this->onZone('admin')->actingAs($manager)->get('/admin/guests')->assertOk()
            ->assertSee('Sample, Bob')->assertDontSee('+41790001122')->assertSee('+4179*****22');
    }

    public function test_filters_by_site_method_active_and_search(): void
    {
        $manager = User::factory()->create();
        $get = fn (array $q) => $this->onZone('admin')->actingAs($manager)->get('/admin/guests?'.http_build_query($q))->assertOk();

        $get(['method' => 'sms'])->assertSee('Sample, Bob')->assertDontSee('Example, Alice')->assertDontSee('Waiting, Carol');
        $get(['method' => 'approval'])->assertSee('Waiting, Carol')->assertDontSee('Sample, Bob');
        $get(['site' => $this->site->id])->assertSee('Example, Alice')->assertDontSee('Sample, Bob');
        $get(['active' => 1])->assertSee('Example, Alice')->assertDontSee('Sample, Bob')->assertDontSee('Waiting, Carol');
        $get(['q' => 'ali'])->assertSee('Example, Alice')->assertDontSee('Waiting, Carol');
        $get(['q' => $this->codeGrant->code])->assertSee('Example, Alice')->assertDontSee('Sample, Bob');
    }

    public function test_disconnect_extend_and_revoke_from_the_list(): void
    {
        Queue::fake();
        $manager = User::factory()->create();

        $this->onZone('admin')->actingAs($manager)->from('/admin/guests')
            ->post("/admin/sessions/{$this->session->id}/disconnect")->assertRedirect('/admin/guests')->assertSessionHasNoErrors();
        Queue::assertPushed(DisconnectSession::class, fn ($job) => $job->sessionSummaryId === $this->session->id);
        $this->assertSame('grant.disconnected', AuditLog::latest('id')->value('action'));

        $this->session->update(['serving_instance_id' => '01REMOTEINSTANCE0000000000']);
        $this->onZone('admin')->actingAs($manager)->from('/admin/guests')
            ->post("/admin/sessions/{$this->session->id}/disconnect")->assertSessionHasErrors('session');
        Queue::assertPushed(DisconnectSession::class, 1);

        $expires = $this->codeGrant->expires_at;
        $this->onZone('admin')->actingAs($manager)->from('/admin/guests')
            ->post("/admin/grants/{$this->codeGrant->id}/extend", ['hours' => 2])->assertRedirect('/admin/guests');
        $this->assertEqualsWithDelta(7200, $expires->diffInSeconds($this->codeGrant->fresh()->expires_at), 1);

        $this->onZone('admin')->actingAs($manager)->from('/admin/guests')
            ->post("/admin/grants/{$this->codeGrant->id}/extend", ['hours' => 1.5])->assertSessionHasErrors('hours');

        $this->onZone('admin')->actingAs($manager)->from('/admin/guests')
            ->post("/admin/grants/{$this->codeGrant->id}/revoke")->assertRedirect('/admin/guests');
        $this->assertNotNull($this->codeGrant->fresh()->revoked_at);

        $this->onZone('admin')->actingAs($manager)
            ->post("/admin/grants/{$this->codeGrant->id}/extend", ['hours' => 2])->assertStatus(422);
    }

    public function test_like_wildcards_are_literal_and_invalid_filters_are_ignored(): void
    {
        $manager = User::factory()->create();
        $get = fn (string $q) => $this->onZone('admin')->actingAs($manager)->get('/admin/guests?'.$q)->assertOk();

        $get('q=%25')->assertDontSee('Example, Alice')->assertDontSee('Sample, Bob');
        $get('q=_lice')->assertDontSee('Example, Alice');
        $this->guest('Al_ce', 'Literal', $this->site, null, null);
        $get('q=l_c')->assertSee('Literal, Al_ce')->assertDontSee('Example, Alice');

        $get('method=bogus&site=nope&active=maybe&q[]=x')
            ->assertSee('Example, Alice')->assertSee('Sample, Bob')->assertSee('Waiting, Carol');
    }

    public function test_open_session_without_recent_accounting_is_shown_as_stale(): void
    {
        $manager = User::factory()->create();
        config(['portal.radius.stale_after' => 900]);
        RadiusAccounting::query()->update(['last_update_at' => now()->subMinutes(5)]);
        $this->onZone('admin')->actingAs($manager)->get('/admin/guests')->assertOk()->assertDontSee('>stale<', false);

        RadiusAccounting::query()->update(['last_update_at' => now()->subMinutes(20)]);
        $this->onZone('admin')->actingAs($manager)->get('/admin/guests')->assertOk()
            ->assertSee('>stale<', false)->assertSee('Disconnect');
        $this->assertNull($this->session->fresh()->stopped_at);
    }

    public function test_session_ended_by_the_guest_is_marked_logged_out(): void
    {
        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->get('/admin/guests')->assertOk()->assertDontSee('data-logged-out', false);
        $this->session->update(['logged_out_at' => now(), 'stopped_at' => now()]);
        $this->onZone('admin')->actingAs($manager)->get('/admin/guests')->assertOk()
            ->assertSee('data-logged-out', false)->assertSee(__('portal.guests.logged_out'));
    }

    private function guest(string $first, string $last, Site $site, ?Grant $grant, ?TermsVersion $terms, ?string $phone = null): Guest
    {
        $guest = Guest::create(['first_name' => $first, 'last_name' => $last, 'phone_e164' => $phone, 'locale' => 'en']);
        TermsAcceptance::create(['guest_id' => $guest->id, 'terms_version_id' => $terms?->id, 'grant_id' => $grant?->id,
            'site_id' => $site->id, 'mac' => self::MAC, 'accepted_at' => now()]);

        return $guest;
    }
}
