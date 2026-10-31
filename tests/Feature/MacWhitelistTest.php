<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\MacWhitelist;
use App\Models\MacWhitelistSite;
use App\Models\NasClient;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Services\GrantManager;
use App\Support\Mac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MAC whitelist (CONCEPT.md §5, §9): admin page, RADIUS MAC authentication, accounting, disconnects.
 */
class MacWhitelistTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:10';

    private Site $site;

    private Site $other;

    private NasClient $nas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']);
        $this->other = Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7']);
        $this->nas = $this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
        Process::fake(['*' => Process::result('Received Disconnect-ACK Id 1')]);
    }

    public function test_crud_toggle_and_audit_for_admin_and_manager(): void
    {
        $manager = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $as = fn (User $u) => $this->onZone('admin')->actingAs($u);

        $this->onZone('admin')->get('/admin/whitelist')->assertRedirect();
        $as($manager)->get('/admin/whitelist')->assertOk();

        $as($manager)->post('/admin/whitelist', ['mac' => 'aa-bb-cc-00-00-10', 'comment' => 'Printer', 'active' => '1',
            'site_ids' => [$this->site->id]])->assertRedirect();
        $entry = MacWhitelist::sole();
        $this->assertSame(self::MAC, $entry->mac);
        $this->assertSame([$this->site->id], $entry->siteIds());
        $this->assertSame($manager->id, $entry->created_by);

        // Duplicate MAC, missing comment and invalid MAC are rejected.
        $as($admin)->post('/admin/whitelist', ['mac' => self::MAC, 'comment' => 'Again'])->assertSessionHasErrors('mac');
        $as($admin)->post('/admin/whitelist', ['mac' => 'AA:BB:CC:00:00:11'])->assertSessionHasErrors('comment');
        $as($admin)->post('/admin/whitelist', ['mac' => 'not-a-mac', 'comment' => 'x'])->assertSessionHasErrors('mac');

        $as($admin)->put("/admin/whitelist/{$entry->id}", ['mac' => self::MAC, 'comment' => 'Office printer', 'active' => '1',
            'expires_at' => now()->addMonth()->format('Y-m-d\TH:i')])->assertRedirect();
        $entry->refresh();
        $this->assertSame('Office printer', $entry->comment);
        $this->assertSame([], $entry->siteIds());
        $this->assertNotNull($entry->expires_at);

        $as($manager)->post("/admin/whitelist/{$entry->id}/toggle")->assertRedirect();
        $this->assertFalse($entry->fresh()->active);
        $as($manager)->get('/admin/whitelist?status=inactive')->assertSee(self::MAC);
        $as($manager)->get('/admin/whitelist?status=active')->assertDontSee(self::MAC);
        $as($manager)->get('/admin/whitelist?q=office')->assertSee(self::MAC);

        $as($manager)->delete("/admin/whitelist/{$entry->id}")->assertRedirect('/admin/whitelist');
        $this->assertSoftDeleted($entry);
        // Deleted entries free the MAC for a new entry.
        $as($manager)->post('/admin/whitelist', ['mac' => self::MAC, 'comment' => 'New'])->assertSessionHasNoErrors();

        $this->assertSame(
            ['whitelist.created', 'whitelist.updated', 'whitelist.deactivated', 'whitelist.deleted', 'whitelist.created'],
            AuditLog::query()->where('action', 'like', 'whitelist.%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_radius_accepts_whitelisted_mac_without_session_timeout(): void
    {
        $entry = $this->entry();

        $reply = $this->authorize(self::MAC, self::MAC)->assertOk()->json();
        $this->assertArrayNotHasKey('reply:Session-Timeout', $reply);
        $this->assertArrayHasKey('reply:Acct-Interim-Interval', $reply);

        // RouterOS notation variants of the same MAC.
        $this->authorize('aa-bb-cc-00-00-10', 'AA-BB-CC-00-00-10')->assertOk();

        // Expiring entry: Session-Timeout bounds the session.
        $entry->update(['expires_at' => now()->addHour()]);
        $this->assertEqualsWithDelta(3600, $this->authorize(self::MAC, self::MAC)->json('reply:Session-Timeout'), 2);
    }

    public function test_radius_rejects_expired_inactive_wrong_site_and_foreign_user_name(): void
    {
        $entry = $this->entry();

        // User-Name must be the Calling-Station-Id reported by the router.
        $this->authorize(self::MAC, 'AA:BB:CC:00:00:99')->assertStatus(401);
        $this->authorize(self::MAC, null)->assertStatus(401);
        // Not whitelisted.
        $this->authorize('AA:BB:CC:00:00:99', 'AA:BB:CC:00:00:99')->assertStatus(401);

        $entry->update(['active' => false]);
        $this->authorize(self::MAC, self::MAC)->assertStatus(401);

        $entry->update(['active' => true, 'expires_at' => now()->subMinute()]);
        $this->authorize(self::MAC, self::MAC)->assertStatus(401);

        $entry->update(['expires_at' => null]);
        $entry->syncSites([$this->other->id]);
        $this->authorize(self::MAC, self::MAC)->assertStatus(401);

        $entry->load('siteLinks')->syncSites([$this->site->id]);
        $this->authorize(self::MAC, self::MAC)->assertOk();

        // Portal credentials flow is unchanged: an unknown non-MAC user name is still rejected.
        $this->authorize('gnotatokenatall1', self::MAC)->assertStatus(401);
    }

    public function test_accounting_creates_session_without_grant_and_delete_disconnects(): void
    {
        $entry = $this->entry();
        $this->accounting('Start', 'mac-1', self::MAC, self::MAC)->assertNoContent();
        // User-Name not equal to Calling-Station-Id: not one of our sessions.
        $this->accounting('Start', 'mac-2', self::MAC, 'AA:BB:CC:00:00:99')->assertNoContent();

        $session = SessionSummary::sole();
        $this->assertNull($session->grant_id);
        $this->assertSame($entry->id, $session->mac_whitelist_id);

        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->get('/admin/whitelist')->assertOk()
            ->assertSee(__('portal.grants.online'))->assertSee('10.5.50.20');
        $this->onZone('admin')->actingAs($manager)->get("/admin/whitelist/{$entry->id}/edit")->assertOk()->assertSee('10.5.50.20');

        // Scheduler leaves valid whitelist sessions alone.
        $this->artisan('portal:enforce-grants')->assertSuccessful();
        Process::assertNothingRan();

        $this->onZone('admin')->actingAs(User::factory()->create())
            ->delete("/admin/whitelist/{$entry->id}")->assertRedirect();
        Process::assertRanTimes(fn (PendingProcess $p) => in_array('10.0.0.1:3799', $p->command, true)
            && $p->input === "Framed-IP-Address = \"10.5.50.20\"\n", 1);
    }

    public function test_accounting_start_without_valid_entry_is_recorded_and_disconnected(): void
    {
        $entry = $this->entry();
        $entry->update(['active' => false]);
        $this->accounting('Start', 'mac-1', self::MAC, self::MAC)->assertNoContent();
        $this->assertSame($entry->id, SessionSummary::sole()->mac_whitelist_id);
        Process::assertRanTimes(fn (PendingProcess $p) => str_contains($p->input, '"10.5.50.20"'), 1);

        $entry->delete();
        $this->accounting('Start', 'mac-2', self::MAC, self::MAC, '10.5.50.21')->assertNoContent();
        $this->assertSame($entry->id, SessionSummary::query()->latest('id')->whereHas('accounting', fn ($q) => $q->where('acct_session_id', 'mac-2'))->value('mac_whitelist_id'));
        Process::assertRanTimes(fn (PendingProcess $p) => str_contains($p->input, '"10.5.50.21"'), 1);
    }

    public function test_reject_path_is_cached_until_the_whitelist_changes(): void
    {
        // Raw inserts (bypassing model events and the cache flush) on purpose.
        $this->withoutFederationWriteGuard();
        $raw = fn (string $mac) => DB::table('mac_whitelist')->insert(['id' => (string) Str::ulid(), 'mac' => $mac,
            'comment' => 'raw', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Empty whitelist: cached "no valid entry" flag.
        $this->authorize(self::MAC, self::MAC)->assertStatus(401);
        $raw(self::MAC);
        $this->authorize(self::MAC, self::MAC)->assertStatus(401);
        MacWhitelist::query()->where('mac', self::MAC)->first()->touch();
        $this->authorize(self::MAC, self::MAC)->assertOk();

        // Negative cache per MAC, invalidated by a site link change.
        $other = 'AA:BB:CC:00:00:20';
        $this->authorize($other, $other)->assertStatus(401);
        $raw($other);
        $this->authorize($other, $other)->assertStatus(401);
        MacWhitelist::query()->where('mac', self::MAC)->first()->syncSites([$this->site->id]);
        $this->authorize($other, $other)->assertOk();

        // Negative entries expire after 60 s.
        $third = 'AA:BB:CC:00:00:30';
        $this->authorize($third, $third)->assertStatus(401);
        $raw($third);
        $this->travel(61)->seconds();
        $this->authorize($third, $third)->assertOk();
    }

    public function test_strict_mac_rejects_trailing_newline_and_other_formats(): void
    {
        $this->assertSame(self::MAC, Mac::strict('aa:bb:cc:00:00:10'));
        $this->assertSame(self::MAC, Mac::strict('AABBCC000010'));
        $this->assertNull(Mac::strict(self::MAC."\n"));
        $this->assertNull(Mac::strict('AA:BB-CC:00:00:10'));
        $this->assertNull(Mac::strict('gaabbcc0000100zz'));
    }

    public function test_purge_removes_deleted_entries_and_links_after_retention(): void
    {
        $deleted = $this->entry();
        $deleted->syncSites([$this->site->id]);
        $deleted->delete();
        $kept = MacWhitelist::create(['mac' => 'AA:BB:CC:00:00:20', 'comment' => 'Kept', 'active' => true]);
        $kept->syncSites([$this->site->id]);
        $kept->load('siteLinks')->syncSites([$this->other->id]);

        $this->travel(400)->days();
        $this->artisan('portal:purge')->assertSuccessful();

        $this->assertSame([$kept->id], MacWhitelist::withTrashed()->pluck('id')->all());
        $this->assertSame([$this->other->id], MacWhitelistSite::withTrashed()->pluck('site_id')->all());
    }

    public function test_scheduler_disconnects_sessions_of_expired_entries(): void
    {
        $entry = $this->entry();
        $entry->update(['expires_at' => now()->addMinutes(30)]);
        $this->accounting('Start', 'mac-1', self::MAC, self::MAC);

        $this->travel(31)->minutes();
        $this->accounting('Interim-Update', 'mac-1', self::MAC, self::MAC);
        $this->artisan('portal:enforce-grants')->assertSuccessful();
        Process::assertRanTimes(fn (PendingProcess $p) => str_contains($p->input, '"10.5.50.20"'), 1);
    }

    public function test_guests_list_offers_permanent_allow_with_prefilled_form(): void
    {
        $manager = User::factory()->create();
        $grant = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => 'first_login']);
        $guest = Guest::create(['first_name' => 'Alice', 'last_name' => 'Example', 'locale' => 'en']);
        TermsAcceptance::create(['guest_id' => $guest->id, 'grant_id' => $grant->id, 'site_id' => $this->site->id,
            'mac' => self::MAC, 'accepted_at' => now()]);
        $device = $grant->devices()->create(['guest_id' => $guest->id, 'mac' => self::MAC, 'bound_at' => now()]);

        // The link carries the device id only, no personal data.
        $create = route('admin.whitelist.create', ['device' => $device->id], false);
        $this->onZone('admin')->actingAs($manager)->get('/admin/guests')->assertOk()
            ->assertSee(e($create), false)->assertSee(__('portal.whitelist.allow_device'))
            ->assertDontSee('comment=', false);

        $this->onZone('admin')->actingAs($manager)->get($create)->assertOk()
            ->assertSee('value="'.self::MAC.'"', false)->assertSee('value="Alice Example"', false)
            ->assertViewHas('selectedSites', [$this->site->id]);

        $this->entry();
        $this->onZone('admin')->actingAs($manager)->get('/admin/guests')->assertOk()
            ->assertSee(__('portal.whitelist.badge'))->assertDontSee(__('portal.whitelist.allow_device'));
    }

    private function entry(): MacWhitelist
    {
        return MacWhitelist::create(['mac' => self::MAC, 'comment' => 'Test device', 'active' => true]);
    }

    private function authorize(string $user, ?string $callingStationId): TestResponse
    {
        return $this->radius('authorize', array_filter([
            'User-Name' => $user, 'User-Password' => '', 'Calling-Station-Id' => $callingStationId,
        ], fn ($v) => $v !== null));
    }

    private function accounting(string $status, string $sessionId, string $user, string $mac, string $ip = '10.5.50.20'): TestResponse
    {
        return $this->radius('accounting', [
            'Acct-Status-Type' => $status, 'Acct-Session-Id' => $sessionId, 'User-Name' => $user,
            'Calling-Station-Id' => $mac, 'Framed-IP-Address' => $ip,
        ]);
    }

    /** Request in rlm_rest JSON format. */
    private function radius(string $endpoint, array $attributes): TestResponse
    {
        $attributes['FreeRADIUS-Client-Shortname'] = $this->nas->shortname();
        $body = array_map(fn ($v) => ['type' => 'string', 'value' => [(string) $v]], $attributes);

        return $this->onZone('internal')
            ->withHeader('Authorization', 'Basic '.base64_encode('radius:test-internal-token'))
            ->postJson('/internal/radius/'.$endpoint, $body);
    }
}
