<?php

namespace Tests\Feature;

use App\Enums\GrantType;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\Site;
use App\Models\SiteCode;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Services\GrantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Codes list filter (current by default) and deletion of ended grants (CONCEPT.md §8).
 */
class GrantDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Grant> one grant per status, label "L-<status>" */
    private array $grants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grants = [
            'pending' => $this->grant('pending', ['valid_from' => now()->addDay()]),
            'unused' => $this->grant('unused'),
            'active' => $this->grant('active', ['first_login_at' => now(), 'expires_at' => now()->addHour()]),
            'lapsed' => $this->grant('lapsed', ['redeem_until' => now()->subHour()]),
            'expired' => $this->grant('expired', ['first_login_at' => now()->subDays(2), 'expires_at' => now()->subDay()]),
            'revoked' => $this->grant('revoked', ['revoked_at' => now()]),
        ];
    }

    private function grant(string $status, array $attributes = []): Grant
    {
        $grant = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => 'first_login', 'label' => 'L-'.$status]);
        if ($attributes !== []) {
            $grant->update($attributes);
        }
        $this->assertSame($status, $grant->status()->value);

        return $grant;
    }

    private function admin(): static
    {
        return $this->onZone('admin')->actingAs(User::factory()->admin()->create());
    }

    private function manager(): static
    {
        return $this->onZone('admin')->actingAs(User::factory()->create());
    }

    public function test_default_list_shows_current_grants_only(): void
    {
        $page = $this->manager()->get('/admin/grants')->assertOk()
            ->assertSee('L-pending')->assertSee('L-unused')->assertSee('L-active')
            ->assertDontSee('L-lapsed')->assertDontSee('L-expired')->assertDontSee('L-revoked')
            ->assertSee('Current')->assertSee(route('admin.grants.index', ['status' => 'all']), false);
        $this->assertStringContainsString('font-semibold">Current<', $page->getContent());

        // Unknown values fall back to the default view.
        $this->manager()->get('/admin/grants?status=bogus')->assertOk()
            ->assertSee('L-unused')->assertDontSee('L-expired');
    }

    public function test_all_and_single_status_filters(): void
    {
        $all = $this->manager()->get('/admin/grants?status=all')->assertOk();
        foreach (array_keys($this->grants) as $status) {
            $all->assertSee('L-'.$status);
        }
        $this->assertStringContainsString('font-semibold">All<', $all->getContent());

        $this->manager()->get('/admin/grants?status=expired')->assertOk()
            ->assertSee('L-expired')->assertDontSee('L-unused')->assertDontSee('L-revoked');
        $this->manager()->get('/admin/grants?status=lapsed')->assertOk()
            ->assertSee('L-lapsed')->assertDontSee('L-active');
    }

    public function test_admin_and_manager_delete_an_ended_grant(): void
    {
        $expired = $this->grants['expired'];
        $this->manager()->get("/admin/grants/{$expired->id}/edit")->assertOk()
            ->assertSee('name="_method" value="DELETE"', false)->assertSee('Delete this ended code?');

        $this->manager()->delete("/admin/grants/{$expired->id}")
            ->assertRedirect('/admin/grants')->assertSessionHas('status', 'Code deleted.');
        $this->assertSoftDeleted($expired);

        $revoked = $this->grants['revoked'];
        $this->admin()->delete("/admin/grants/{$revoked->id}")->assertRedirect('/admin/grants');
        $this->assertSoftDeleted($revoked);

        // Gone from every view, including "all" and the edit page; print skips it.
        $this->manager()->get('/admin/grants?status=all')->assertDontSee('L-expired')->assertSee('L-lapsed');
        $this->manager()->get("/admin/grants/{$expired->id}/edit")->assertNotFound();
        $this->manager()->get('/admin/grants/print?ids[]='.$expired->id.'&ids[]='.$this->grants['unused']->id)
            ->assertOk()->assertDontSee($expired->code)->assertSee($this->grants['unused']->code);
    }

    public function test_deletion_is_audited_without_the_code_and_records_a_tombstone_event(): void
    {
        $lapsed = $this->grants['lapsed'];
        $this->manager()->delete("/admin/grants/{$lapsed->id}")->assertRedirect();

        $entry = AuditLog::query()->where('action', 'grant.deleted')->sole();
        $this->assertSame($lapsed->id, $entry->subject_id);
        $this->assertSame(['label' => 'L-lapsed', 'type' => 'code', 'status' => 'lapsed'], $entry->data);
        $this->assertStringNotContainsString($lapsed->code, json_encode($entry->data));

        $event = DB::table('federation_events')->where('table', 'grants')->where('record_id', $lapsed->id)->orderByDesc('seq')->first();
        $payload = json_decode($event->payload, true);
        $this->assertNotNull($payload['deleted_at']);
        $this->assertSame(Grant::withTrashed()->find($lapsed->id)->version, $payload['version']);
    }

    public function test_usable_grants_are_not_deletable(): void
    {
        foreach (['pending', 'unused', 'active'] as $status) {
            $grant = $this->grants[$status];
            $this->manager()->get("/admin/grants/{$grant->id}/edit")->assertOk()
                ->assertDontSee('name="_method" value="DELETE"', false)->assertDontSee('Delete this ended code?');
            $this->manager()->from("/admin/grants/{$grant->id}/edit")->delete("/admin/grants/{$grant->id}")
                ->assertRedirect("/admin/grants/{$grant->id}/edit")
                ->assertSessionHasErrors(['grant' => 'Only ended codes can be deleted.']);
            $this->assertNotSoftDeleted($grant);
        }
        $this->assertSame(0, AuditLog::query()->where('action', 'grant.deleted')->count());
    }

    public function test_bulk_delete_removes_ended_and_skips_current_grants(): void
    {
        $this->manager()->get('/admin/grants?status=all')->assertOk()
            ->assertSee('formaction="'.route('admin.grants.destroy-selected').'"', false)->assertSee('Delete selected');

        $ids = array_map(fn (Grant $g) => $g->id, $this->grants);
        $this->manager()->from('/admin/grants?status=all')->post('/admin/grants/delete', ['ids' => array_values($ids)])
            ->assertRedirect('/admin/grants?status=all')
            ->assertSessionHas('status', '3 deleted, 3 skipped (still valid).');

        foreach (['lapsed', 'expired', 'revoked'] as $status) {
            $this->assertSoftDeleted($this->grants[$status]);
        }
        foreach (['pending', 'unused', 'active'] as $status) {
            $this->assertNotSoftDeleted($this->grants[$status]);
        }
        $entry = AuditLog::query()->where('action', 'grant.bulk_deleted')->sole();
        $this->assertSame(['count' => 3, 'skipped' => 3], $entry->data);
        $this->assertSame(0, AuditLog::query()->where('action', 'grant.deleted')->count());
    }

    public function test_bulk_delete_with_only_current_grants_deletes_nothing(): void
    {
        $this->admin()->post('/admin/grants/delete', ['ids' => [$this->grants['unused']->id, 'not-a-grant']])
            ->assertSessionHas('status', '0 deleted, 1 skipped (still valid).');
        $this->assertNotSoftDeleted($this->grants['unused']);
        $this->assertSame(0, AuditLog::query()->where('action', 'grant.bulk_deleted')->count());
    }

    public function test_guests_list_marks_a_deleted_grant(): void
    {
        $site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']);
        $grant = $this->grants['expired'];
        $guest = Guest::create(['first_name' => 'Dana', 'last_name' => 'Example', 'locale' => 'en']);
        TermsAcceptance::create(['guest_id' => $guest->id, 'grant_id' => $grant->id, 'site_id' => $site->id,
            'mac' => 'AA:BB:CC:00:00:09', 'accepted_at' => now()]);
        $grant->devices()->create(['guest_id' => $guest->id, 'mac' => 'AA:BB:CC:00:00:09', 'bound_at' => now()->subDays(2)]);

        app(GrantManager::class)->delete($grant);

        $this->manager()->get('/admin/guests')->assertOk()
            ->assertSee('Example, Dana')->assertSee($grant->code)->assertSee('deleted')->assertSee('Code')
            ->assertDontSee(route('admin.grants.edit', $grant), false)
            ->assertDontSee(route('admin.grants.extend', $grant), false)
            ->assertDontSee(route('admin.grants.revoke', $grant), false);
    }

    public function test_status_page_of_a_deleted_grant_reports_it_as_ended(): void
    {
        $grant = $this->grants['revoked'];
        app(GrantManager::class)->delete($grant);

        $url = URL::temporarySignedRoute('guest.status', now()->addHour(), ['grant' => $grant->id, 'token' => 1], absolute: false);
        $this->onZone('guest')->get($url)->assertForbidden();
    }

    public function test_site_code_history_keeps_login_counts_of_deleted_device_grants(): void
    {
        $site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']);
        $siteCode = SiteCode::create(['site_id' => $site->id, 'code' => '482913', 'duration_s' => 3600, 'active' => true]);
        $device = Grant::create(['type' => GrantType::SiteCode, 'code' => '482913', 'site_code_id' => $siteCode->id,
            'duration_s' => 3600, 'expiry_mode' => 'creation', 'expires_at' => now()->subMinute()]);

        app(GrantManager::class)->delete($device);

        $this->assertSoftDeleted($device);
        $history = $this->manager()->get("/admin/sites/{$site->id}/site-code")->assertOk()->viewData('history');
        $this->assertSame(1, $history->first()->grants_count);
    }
}
