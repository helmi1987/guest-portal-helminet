<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\Applier;
use App\Federation\Federation;
use App\Federation\FutureVersionException;
use App\Models\Grant;
use App\Models\MacWhitelist;
use App\Models\SessionSummary;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\FederationWriteGuard;
use Tests\TestCase;

/**
 * Federation part A: persistent HLC, outbox events recorded with every replicated write.
 */
class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private function grant(array $attributes = []): Grant
    {
        return Grant::create(array_merge(['type' => GrantType::Code, 'code' => '123456', 'duration_s' => 3600,
            'expiry_mode' => ExpiryMode::FirstLogin], $attributes));
    }

    /** @return list<object> */
    private function events(string $table, string $id): array
    {
        return DB::table('federation_events')->where('table', $table)->where('record_id', $id)->orderBy('seq')->get()->all();
    }

    public function test_create_update_delete_restore_record_events_with_full_rows(): void
    {
        $grant = $this->grant();
        $grant->update(['label' => 'Lobby']);
        $grant->delete();
        $tombstoneVersion = $grant->version;
        $grant->restore();
        $grant->forceDelete();

        $events = $this->events('grants', $grant->id);
        $this->assertCount(4, $events);
        $origin = app(LocalInstance::class)->id();
        $seqs = array_map(fn ($e) => $e->seq, $events);
        $this->assertSame($seqs, array_values(array_unique($seqs)));
        $this->assertSame($seqs, collect($seqs)->sort()->values()->all());

        $created = json_decode($events[0]->payload, true);
        $this->assertSame($origin, $events[0]->origin);
        $this->assertSame(Federation::FEDERATION_PROTOCOL, (int) $events[0]->proto);
        // DB defaults are part of the payload (read back from the row).
        $this->assertSame(0, (int) $created['extensions_used']);
        $this->assertSame($events[0]->version, $created['version']);
        $this->assertSame('Lobby', json_decode($events[1]->payload, true)['label']);

        // The tombstone carries its own, newer version (also persisted in the row).
        $tombstone = json_decode($events[2]->payload, true);
        $this->assertNotNull($tombstone['deleted_at']);
        $this->assertSame($tombstoneVersion, $tombstone['version']);
        $this->assertGreaterThan($events[1]->version, $events[2]->version);
        $this->assertNull(json_decode($events[3]->payload, true)['deleted_at']);
        $this->assertGreaterThan($events[2]->version, $events[3]->version);
    }

    public function test_event_is_rolled_back_with_the_write(): void
    {
        try {
            DB::transaction(function () {
                $this->grant(['label' => 'rolled back']);
                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Grant::query()->where('label', 'rolled back')->count());
        $this->assertSame(0, DB::table('federation_events')->where('payload', 'like', '%rolled back%')->count());
    }

    public function test_local_tables_with_replication_columns_record_no_events(): void
    {
        $site = Site::create(['slug' => 'a', 'name' => 'A', 'driver' => 'mikrotik_ros7']);
        $summary = SessionSummary::create(['site_id' => $site->id, 'mac' => 'AA:BB:CC:DD:EE:FF', 'started_at' => now()]);

        $this->assertFalse($summary->isFederated());
        $this->assertSame([], $this->events('session_summaries', $summary->id));
        $this->assertCount(1, $this->events('sites', $site->id));
    }

    public function test_saving_only_local_columns_records_no_event_and_keeps_the_version(): void
    {
        $site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']);
        $version = $site->version;
        $count = count($this->events('sites', $site->id));

        // Local columns only (Federation::LOCAL_COLUMNS): no new version, no event
        $site->forceFill(['wifi_masters_local' => ['local' => ['wifi1']], 'wifi_caps_local' => ['capsman2' => []]])->save();
        $this->assertSame($version, $site->fresh()->version);
        $this->assertCount($count, $this->events('sites', $site->id));

        // A replicated column changed together with a local one: one event, new version
        $site->forceFill(['name' => 'Site B', 'wifi_password_local' => null])->save();
        $this->assertNotSame($version, $site->fresh()->version);
        $this->assertCount($count + 1, $this->events('sites', $site->id));
    }

    public function test_increment_on_a_replicated_model_records_an_event(): void
    {
        $grant = $this->grant();
        $before = $grant->version;
        $grant->increment('extensions_used');

        $this->assertSame(1, $grant->fresh()->extensions_used);
        $this->assertGreaterThan($before, $grant->fresh()->version);
        $this->assertCount(2, $this->events('grants', $grant->id));
    }

    public function test_setting_id_is_derived_from_the_key(): void
    {
        Setting::put('retention_days', 30);
        Setting::put('retention_days', 40);

        $id = Setting::query()->where('key', 'retention_days')->value('id');
        $this->assertSame(Setting::idFor('retention_days'), $id);
        $this->assertTrue(Str::isUlid($id));
        $this->assertNotSame(Setting::idFor('a'), Setting::idFor('b'));
        $this->assertCount(2, $this->events('settings', $id));
    }

    public function test_guard_detects_query_builder_writes_without_event(): void
    {
        $grant = $this->grant();
        Grant::query()->whereKey($grant->id)->update(['label' => 'bypass']);

        $this->assertSame(["same_version grants#{$grant->id} version {$grant->version}"], FederationWriteGuard::violations());
        $this->withoutFederationWriteGuard();
    }

    public function test_hlc_is_monotonic_and_resumes_from_stored_versions(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        $hlc = app(Hlc::class);
        $a = $hlc->now();
        $b = $hlc->now();
        $this->assertGreaterThan($a, $b);
        $this->grant();
        $stored = DB::table('federation_events')->max('version');

        // A fresh clock (new process) with the wall clock behind still sorts after the stored version.
        Carbon::setTestNow('2026-10-30 11:00:00');
        $fresh = new Hlc(app(LocalInstance::class));
        $this->assertGreaterThan($stored, $fresh->now());
    }

    public function test_hlc_receive_merges_remote_versions_and_rejects_far_future(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        $hlc = app(Hlc::class);
        $wall = Hlc::wallMs();
        $remote = Hlc::format($wall + 60_000, 7, '01ZZZZZZZZZZZZZZZZZZZZZZZZ');
        $hlc->receive($remote);
        $this->assertGreaterThan($remote, $hlc->now());

        $this->expectException(FutureVersionException::class);
        $hlc->receive(Hlc::format($wall + 301_000, 0, '01ZZZZZZZZZZZZZZZZZZZZZZZZ'));
    }

    public function test_versions_tie_break_by_instance_id(): void
    {
        $this->assertLessThan(Hlc::format(1, 0, '01B'), Hlc::format(1, 0, '01A'));
        $this->assertLessThan(Hlc::format(1, 1, '01A'), Hlc::format(1, 0, '01B'));
        $this->assertLessThan(Hlc::format(2, 0, '01A'), Hlc::format(1, 99, '01Z'));
    }

    public function test_conflict_resolution_of_the_apply_engine_passes_the_guard(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        $local = MacWhitelist::create(['mac' => 'AA:BB:CC:DD:EE:FF', 'comment' => 'local']);
        $origin = '01JREMOTE00000000000000000';
        $version = Hlc::format(Hlc::wallMs() + 1000, 0, $origin);
        $id = '01JWHITELIST00000000000000';

        $result = app(Applier::class)->apply($origin, [['origin' => $origin, 'seq' => 1, 'table' => 'mac_whitelist',
            'record_id' => $id, 'version' => $version, 'payload' => ['id' => $id, 'mac' => 'AA:BB:CC:DD:EE:FF',
                'comment' => 'remote', 'active' => 1, 'created_at' => '2026-10-30 12:00:01', 'updated_at' => '2026-10-30 12:00:01']]]);

        $this->assertSame('ok', $result->status);
        $this->assertTrue($local->fresh()->trashed());
        $this->assertSame([], FederationWriteGuard::violations());
    }
}
