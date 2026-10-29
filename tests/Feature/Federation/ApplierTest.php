<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\AppliedChange;
use App\Federation\Applier;
use App\Federation\ApplyHooks;
use App\Federation\DataKey;
use App\Federation\Federation;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Grant;
use App\Models\MacWhitelist;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteCode;
use App\Models\TermsVersion;
use App\Models\User;
use App\Support\Hlc;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Federation part C: apply engine with simulated instances (own in-memory databases).
 */
class ApplierTest extends TestCase
{
    use RefreshDatabase, SimulatesInstances;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 10:00:00');
        $this->makeInstances('a', 'b', 'c');
    }

    private function at(string $time): void
    {
        Carbon::setTestNow($time);
    }

    private function grantOn(string $name, array $attributes = []): Grant
    {
        return $this->on($name, fn () => Grant::create(array_merge(['type' => GrantType::Code, 'code' => '123456',
            'duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin], $attributes)));
    }

    /** Payload-level comparison of a row on all given instances. */
    private function assertSameRowOn(string $table, string $id, string ...$names): void
    {
        $rows = array_map(fn ($n) => $this->rowOn($n, $table, $id), $names);
        foreach ($rows as $row) {
            $this->assertNotNull($row);
            $this->assertEquals($rows[0], $row);
        }
    }

    public function test_rows_replicate_raw_and_reapply_is_idempotent(): void
    {
        $site = $this->on('a', fn () => Site::create(['slug' => 'lobby', 'name' => 'Lobby', 'driver' => 'mikrotik_ros7']));
        $grant = $this->grantOn('a');
        $this->on('a', fn () => Grant::find($grant->id)->syncSites([$site->id]));

        $results = $this->sync('a', 'b');
        $result = $results[$this->idOf('a')];
        $this->assertSame('ok', $result->status);
        $this->assertSame(0, $result->skipped);
        $this->assertSameRowOn('grants', $grant->id, 'a', 'b');
        $this->assertSameRowOn('sites', $site->id, 'a', 'b');
        $this->assertSame([$site->id], $this->on('b', fn () => Grant::find($grant->id)->siteIds()));

        // Applying does not create local events; foreign events are stored for relay.
        $idA = $this->idOf('a');
        $idB = $this->idOf('b');
        $this->assertSame(0, $this->on('b', fn () => DB::table('federation_events')->where('origin', $idB)
            ->where('table', '!=', 'instances')->count()));
        $countA = $this->on('a', fn () => DB::table('federation_events')->where('origin', $idA)->count());
        $this->assertSame($countA, $this->on('b', fn () => DB::table('federation_events')->where('origin', $idA)->count()));
        $this->assertSame($countA, (int) $this->on('b', fn () => DB::table('peer_cursors')->where('origin', $idA)->value('last_seq')));

        // Re-apply: everything skipped, nothing changes.
        $again = $this->sync('a', 'b')[$idA];
        $this->assertSame(0, $again->applied);
        $this->assertSame($countA, $again->skipped);

        // Relay: c receives a's events from b.
        $this->sync('b', 'c');
        $this->assertSameRowOn('grants', $grant->id, 'a', 'c');
    }

    public function test_last_writer_wins_in_both_directions(): void
    {
        $grant = $this->grantOn('a');
        $this->sync('a', 'b');

        $this->at('2026-10-30 10:01:00');
        $this->on('a', fn () => Grant::find($grant->id)->update(['label' => 'from a']));
        $this->at('2026-10-30 10:02:00');
        $this->on('b', fn () => Grant::find($grant->id)->update(['label' => 'from b']));

        $this->sync('a', 'b');
        $this->sync('b', 'a');

        $this->assertSame('from b', $this->rowOn('a', 'grants', $grant->id)['label']);
        $this->assertSameRowOn('grants', $grant->id, 'a', 'b');
    }

    public function test_equal_clock_ties_are_broken_by_instance_id(): void
    {
        $grant = $this->grantOn('a');
        $this->sync('a', 'b');

        $this->at('2026-10-30 10:05:00');
        $this->on('a', fn () => Grant::find($grant->id)->update(['label' => 'from a']));
        $this->on('b', fn () => Grant::find($grant->id)->update(['label' => 'from b']));
        [$msA, $counterA] = Hlc::parse($this->rowOn('a', 'grants', $grant->id)['version']);
        [$msB, $counterB] = Hlc::parse($this->rowOn('b', 'grants', $grant->id)['version']);
        $this->assertSame([$msA, $counterA], [$msB, $counterB]);

        $this->syncAll('a', 'b');

        $expected = strcmp($this->idOf('a'), $this->idOf('b')) > 0 ? 'from a' : 'from b';
        $this->assertSame($expected, $this->rowOn('a', 'grants', $grant->id)['label']);
        $this->assertSameRowOn('grants', $grant->id, 'a', 'b');
    }

    public function test_grant_fields_merge_revocation_sticky_and_extensions_max(): void
    {
        $grant = $this->grantOn('a');
        $this->sync('a', 'b');

        $this->at('2026-10-30 10:01:00');
        $this->on('a', function () use ($grant) {
            $g = Grant::find($grant->id);
            $g->update(['revoked_at' => now(), 'extensions_used' => 2]);
        });
        // Later edit on b without the revocation (partition).
        $this->at('2026-10-30 10:03:00');
        $this->on('b', fn () => Grant::find($grant->id)->update(['label' => 'later edit', 'extensions_used' => 1]));

        $this->syncAll('a', 'b');

        foreach (['a', 'b'] as $name) {
            $row = $this->rowOn($name, 'grants', $grant->id);
            $this->assertSame('2026-10-30 10:01:00', $row['revoked_at']);
            $this->assertSame('later edit', $row['label']);
            $this->assertSame(2, (int) $row['extensions_used']);
        }
        $this->assertSameRowOn('grants', $grant->id, 'a', 'b');
    }

    public function test_double_redemption_merges_to_one_active_device(): void
    {
        $grant = $this->grantOn('a');
        $this->sync('a', 'b');
        $hooked = [];
        app(ApplyHooks::class)->register('devices', function (AppliedChange $c) use (&$hooked) {
            $hooked[] = [$c->id, $c->reason];
        });

        // Partition: the same code is redeemed at both instances with different devices.
        $this->at('2026-10-30 11:00:00');
        $deviceA = $this->on('a', function () use ($grant) {
            Grant::find($grant->id)->update(['first_login_at' => now(), 'expires_at' => now()->addHour()]);

            return Device::create(['grant_id' => $grant->id, 'mac' => 'AA:AA:AA:AA:AA:01', 'bound_at' => now()]);
        });
        $this->at('2026-10-30 11:10:00');
        $deviceB = $this->on('b', function () use ($grant) {
            Grant::find($grant->id)->update(['first_login_at' => now(), 'expires_at' => now()->addHour()]);

            return Device::create(['grant_id' => $grant->id, 'mac' => 'BB:BB:BB:BB:BB:02', 'bound_at' => now()]);
        });

        $this->syncAll('a', 'b', 'c');

        foreach (['a', 'b', 'c'] as $name) {
            $g = $this->rowOn($name, 'grants', $grant->id);
            $this->assertSame('2026-10-30 11:00:00', $g['first_login_at']);
            $this->assertSame('2026-10-30 12:00:00', $g['expires_at']);
            // Newer binding wins, the older device is unbound at the newer binding time.
            $this->assertNull($this->rowOn($name, 'devices', $deviceB->id)['unbound_at']);
            $this->assertSame('2026-10-30 11:10:00', $this->rowOn($name, 'devices', $deviceA->id)['unbound_at']);
        }
        $this->assertSameRowOn('devices', $deviceA->id, 'a', 'b', 'c');
        $this->assertSameRowOn('grants', $grant->id, 'a', 'b', 'c');
        // Instance a got a conflict change for its own (older) device: the disconnect hook point.
        $this->assertContains([$deviceA->id, 'conflict'], $hooked);
    }

    public function test_redemption_on_one_side_starts_the_deferred_expiry(): void
    {
        $grant = $this->grantOn('a');
        $this->sync('a', 'b');
        $this->at('2026-10-30 11:00:00');
        $this->on('b', fn () => Grant::find($grant->id)->update(['first_login_at' => now(), 'expires_at' => now()->addHour()]));
        // Newer unrelated edit on a, still unredeemed there.
        $this->at('2026-10-30 11:05:00');
        $this->on('a', fn () => Grant::find($grant->id)->update(['note' => 'note']));

        $this->syncAll('a', 'b');

        foreach (['a', 'b'] as $name) {
            $row = $this->rowOn($name, 'grants', $grant->id);
            $this->assertSame('2026-10-30 11:00:00', $row['first_login_at']);
            $this->assertSame('2026-10-30 12:00:00', $row['expires_at']);
            $this->assertSame('note', $row['note']);
        }
    }

    public function test_settings_with_the_same_key_converge(): void
    {
        $this->on('a', fn () => Setting::put('retention_days', 30));
        $this->at('2026-10-30 10:01:00');
        $this->on('b', fn () => Setting::put('retention_days', 60));

        $this->syncAll('a', 'b');

        $id = Setting::idFor('retention_days');
        $this->assertSame(60, $this->on('a', fn () => Setting::get('retention_days')));
        $this->assertSameRowOn('settings', $id, 'a', 'b');
        $this->assertSame(1, $this->on('a', fn () => Setting::query()->where('key', 'retention_days')->count()));
    }

    public function test_site_slug_and_user_email_conflicts_keep_the_older_id(): void
    {
        $siteA = $this->on('a', fn () => Site::create(['slug' => 'lobby', 'name' => 'Lobby A', 'driver' => 'mikrotik_ros7']));
        $userA = $this->on('a', fn () => User::factory()->create(['email' => 'admin@example.com']));
        $this->on('a', fn () => User::find($userA->id)->forceFill(['oidc_subject' => 'iss sub'])->save());
        usleep(2000);
        $siteB = $this->on('b', fn () => Site::create(['slug' => 'lobby', 'name' => 'Lobby B', 'driver' => 'mikrotik_ros7']));
        $userB = $this->on('b', fn () => User::factory()->create(['email' => 'admin@example.com']));
        $this->on('b', fn () => User::find($userB->id)->forceFill(['oidc_subject' => 'iss sub'])->save());
        [$siteWin, $siteLose] = strcmp($siteA->id, $siteB->id) < 0 ? [$siteA, $siteB] : [$siteB, $siteA];
        [$userWin, $userLose] = strcmp($userA->id, $userB->id) < 0 ? [$userA, $userB] : [$userB, $userA];

        $this->syncAll('a', 'b');

        $suffix = fn (string $id) => '-dup-'.strtolower(substr($id, -6));
        foreach (['a', 'b'] as $name) {
            $this->assertSame('lobby', $this->rowOn($name, 'sites', $siteWin->id)['slug']);
            $this->assertSame('lobby'.$suffix($siteLose->id), $this->rowOn($name, 'sites', $siteLose->id)['slug']);
            $this->assertSame('admin@example.com', $this->rowOn($name, 'users', $userWin->id)['email']);
            $this->assertSame('admin'.$suffix($userLose->id).'@example.com', $this->rowOn($name, 'users', $userLose->id)['email']);
            $this->assertSame('iss sub', $this->rowOn($name, 'users', $userWin->id)['oidc_subject']);
            $this->assertSame('iss sub'.$suffix($userLose->id), $this->rowOn($name, 'users', $userLose->id)['oidc_subject']);
            // One audit entry per conflict, same id on every instance.
            $this->assertSame(3, $this->on($name, fn () => AuditLog::where('action', 'federation.conflict')->count()));
        }
        $this->assertSameRowOn('sites', $siteLose->id, 'a', 'b');
        $this->assertSameRowOn('users', $userLose->id, 'a', 'b');
    }

    public function test_mac_whitelist_newer_entry_wins_other_is_tombstoned(): void
    {
        $older = $this->on('a', fn () => MacWhitelist::create(['mac' => 'AA:BB:CC:DD:EE:FF', 'comment' => 'a']));
        $this->at('2026-10-30 10:01:00');
        $newer = $this->on('b', fn () => MacWhitelist::create(['mac' => 'AA:BB:CC:DD:EE:FF', 'comment' => 'b']));

        $this->syncAll('a', 'b');

        foreach (['a', 'b'] as $name) {
            $this->assertNull($this->rowOn($name, 'mac_whitelist', $newer->id)['deleted_at']);
            $this->assertSame('2026-10-30 10:01:00', $this->rowOn($name, 'mac_whitelist', $older->id)['deleted_at']);
            $this->assertSame($newer->id, $this->on($name, fn () => MacWhitelist::query()->where('mac', 'AA:BB:CC:DD:EE:FF')->sole()->id));
        }
        $this->assertSameRowOn('mac_whitelist', $older->id, 'a', 'b');
    }

    public function test_one_active_site_code_per_site(): void
    {
        $site = $this->on('a', fn () => Site::create(['slug' => 'lobby', 'name' => 'Lobby', 'driver' => 'mikrotik_ros7']));
        $this->sync('a', 'b');
        $old = $this->on('a', fn () => SiteCode::create(['site_id' => $site->id, 'code' => '111111', 'duration_s' => 3600]));
        $this->at('2026-10-30 10:01:00');
        $new = $this->on('b', fn () => SiteCode::create(['site_id' => $site->id, 'code' => '222222', 'duration_s' => 3600]));

        $this->syncAll('a', 'b');

        foreach (['a', 'b'] as $name) {
            $this->assertSame([$new->id], $this->on($name, fn () => SiteCode::query()->active()->pluck('id')->all()));
            $this->assertSame('2026-10-30 10:01:00', $this->rowOn($name, 'site_codes', $old->id)['deactivated_at']);
        }
        $this->assertSameRowOn('site_codes', $old->id, 'a', 'b');
    }

    public function test_terms_with_the_same_number_are_both_kept(): void
    {
        $a = $this->on('a', fn () => TermsVersion::create(['locale' => 'de', 'number' => 1, 'body' => 'A', 'published_at' => now()]));
        $this->at('2026-10-30 10:01:00');
        $b = $this->on('b', fn () => TermsVersion::create(['locale' => 'de', 'number' => 1, 'body' => 'B', 'published_at' => now()]));

        $this->syncAll('a', 'b');

        $this->assertSame(2, $this->on('a', fn () => TermsVersion::query()->where('locale', 'de')->count()));
        $this->assertSame($b->id, $this->on('a', fn () => TermsVersion::latestFor('de')->id));
        $this->assertSame($b->id, $this->on('b', fn () => TermsVersion::latestFor('de')->id));
        $this->assertNotNull($a->id);
    }

    public function test_tombstones_replicate_and_old_events_for_unknown_rows_are_dropped(): void
    {
        $grant = $this->grantOn('a');
        $this->sync('a', 'b');
        $this->on('a', fn () => MacWhitelist::create(['mac' => '11:22:33:44:55:66', 'comment' => 'x'])->delete());
        $this->sync('a', 'b');
        $this->assertNotNull($this->on('b', fn () => MacWhitelist::onlyTrashed()->where('mac', '11:22:33:44:55:66')->first()));

        // A row purged on c long ago: events older than the retention period do not bring it back.
        $old = Hlc::format(Hlc::wallMs() - 400 * 86_400_000, 0, $this->idOf('a'));
        $idA = $this->idOf('a');
        $result = $this->on('c', fn () => app(Applier::class)->apply($idA, [[
            'origin' => $idA, 'seq' => 1, 'table' => 'guests', 'record_id' => '01JGUEST000000000000000000', 'version' => $old,
            'payload' => ['id' => '01JGUEST000000000000000000', 'first_name' => 'Old', 'last_name' => 'Guest', 'version' => $old],
        ]]));
        $this->assertSame(1, $result->dropped);
        $this->assertSame(1, $result->lastSeq);
        $this->assertNull($this->rowOn('c', 'guests', '01JGUEST000000000000000000'));
        $this->assertNotNull($grant->id);
    }

    public function test_out_of_order_batch_is_sorted_and_gaps_wait(): void
    {
        $this->grantOn('a', ['label' => 'one']);
        $this->grantOn('a', ['label' => 'two']);
        $this->grantOn('a', ['label' => 'three']);
        $idA = $this->idOf('a');
        $events = $this->on('a', fn () => DB::table('federation_events')->where('origin', $idA)->orderBy('seq')->get()->all());
        $count = count($events);

        // Without the second to last event: stops at the gap.
        $partial = array_reverse(array_values(array_filter($events, fn ($e) => $e->seq !== $count - 1)));
        $result = $this->on('b', fn () => app(Applier::class)->apply($idA, $partial));
        $this->assertSame('stopped', $result->status);
        $this->assertStringContainsString('gap', $result->reason);
        $this->assertSame($count - 2, $result->lastSeq);

        $result = $this->on('b', fn () => app(Applier::class)->apply($idA, array_reverse($events)));
        $this->assertSame('ok', $result->status);
        $this->assertSame($count, $result->lastSeq);
        $this->assertSame(['one', 'three', 'two'], $this->on('b', fn () => Grant::query()->orderBy('label')->pluck('label')->all()));
    }

    public function test_missing_parent_from_another_origin_waits(): void
    {
        $site = $this->on('a', fn () => Site::create(['slug' => 'lobby', 'name' => 'Lobby', 'driver' => 'mikrotik_ros7']));
        $this->sync('a', 'b');
        $grant = $this->grantOn('b');
        $this->on('b', fn () => Grant::find($grant->id)->syncSites([$site->id]));
        $idA = $this->idOf('a');
        $idB = $this->idOf('b');

        // c receives b's events before a's: the site link waits for its parent.
        $bEvents = $this->on('b', fn () => DB::table('federation_events')->where('origin', $idB)->get()->all());
        $result = $this->on('c', fn () => app(Applier::class)->apply($idB, $bEvents));
        $this->assertSame('stopped', $result->status);
        $this->assertStringContainsString('missing parent', $result->reason);
        $this->assertNotNull($this->rowOn('c', 'grants', $grant->id));

        $this->sync('a', 'c');
        $result = $this->on('c', fn () => app(Applier::class)->apply($idB, $bEvents));
        $this->assertSame('ok', $result->status);
        $this->assertSame([$site->id], $this->on('c', fn () => Grant::find($grant->id)->siteIds()));
        $this->assertNotNull($idA);
    }

    public function test_unknown_table_and_higher_protocol_pause_the_origin(): void
    {
        $idA = $this->idOf('a');
        $version = Hlc::format(Hlc::wallMs(), 0, $idA);
        $event = ['origin' => $idA, 'seq' => 1, 'table' => 'future_things', 'record_id' => 'x', 'version' => $version, 'payload' => []];

        $result = $this->on('b', fn () => app(Applier::class)->apply($idA, [$event]));
        $this->assertSame('paused', $result->status);
        $this->assertStringContainsString('unknown table future_things', $result->reason);
        $this->assertSame(0, $result->lastSeq);
        // Stays paused for later batches.
        $grantEvent = $this->on('a', function () use ($idA) {
            $this->grantOn('a');

            return DB::table('federation_events')->where('origin', $idA)->where('table', 'grants')->first();
        });
        $this->assertSame('paused', $this->on('b', fn () => app(Applier::class)->apply($idA, [$grantEvent]))->status);

        $this->on('b', fn () => app(Applier::class)->unpause($idA));
        $higher = (array) $grantEvent;
        $higher['proto'] = Federation::FEDERATION_PROTOCOL + 1;
        $higher['seq'] = 1;
        $result = $this->on('b', fn () => app(Applier::class)->apply($idA, [$higher]));
        $this->assertSame('paused', $result->status);
        $this->assertStringContainsString('protocol', $result->reason);
    }

    public function test_versions_too_far_ahead_stop_the_batch(): void
    {
        $idA = $this->idOf('a');
        $grant = $this->grantOn('a');
        $events = $this->on('a', fn () => DB::table('federation_events')->where('origin', $idA)->orderBy('seq')->get()->all());

        $this->at('2026-10-30 09:50:00'); // b's clock is 10 min behind
        $result = $this->on('b', fn () => app(Applier::class)->apply($idA, $events));
        $this->assertSame('stopped', $result->status);
        $this->assertStringContainsString('future', $result->reason);
        $this->assertNull($this->rowOn('b', 'grants', $grant->id));

        $this->at('2026-10-30 10:00:00');
        $this->assertSame('ok', $this->on('b', fn () => app(Applier::class)->apply($idA, $events))->status);
    }

    public function test_unknown_columns_are_ignored_and_missing_columns_default(): void
    {
        $idA = $this->idOf('a');
        $version = Hlc::format(Hlc::wallMs(), 0, $idA);
        $id = '01JGRANT000000000000000000';
        $result = $this->on('b', fn () => app(Applier::class)->apply($idA, [[
            'origin' => $idA, 'seq' => 1, 'table' => 'grants', 'record_id' => $id, 'version' => $version,
            'payload' => ['id' => $id, 'type' => 'code', 'code' => '654321', 'duration_s' => 60, 'expiry_mode' => 'first_login',
                'created_at' => '2026-10-30 10:00:00', 'updated_at' => '2026-10-30 10:00:00', 'colour_of_the_day' => 'blue'],
        ]]));

        $this->assertSame('ok', $result->status);
        $row = $this->rowOn('b', 'grants', $id);
        $this->assertSame(0, (int) $row['extensions_used']);
        $this->assertSame($version, $row['version']);
        $this->assertArrayNotHasKey('colour_of_the_day', $row);
    }

    public function test_federated_secrets_are_readable_on_peers_sharing_the_data_key(): void
    {
        $user = $this->on('a', fn () => User::factory()->create());
        $this->on('a', fn () => User::find($user->id)->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP'])->save());
        $this->syncAll('a', 'b', 'c');

        // b received the federation key (pairing, later part), c did not.
        [$kid, $material] = $this->on('a', fn () => [app(DataKey::class)->currentId(), app(DataKey::class)->key(app(DataKey::class)->currentId())]);
        $this->on('b', fn () => app(DataKey::class)->import($kid, $material, makeCurrent: true));

        $this->assertSame('JBSWY3DPEHPK3PXP', $this->on('b', fn () => User::find($user->id)->totp_secret));
        $this->expectException(DecryptException::class);
        $this->on('c', fn () => User::find($user->id)->totp_secret);
    }
}
