<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\Applier;
use App\Federation\DataKey;
use App\Models\Grant;
use App\Models\LocalSetting;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Federation regressions: poison events, liveness of waiting events, write cost of local tables,
 * no-op saves, HLC counter overflow, SQLite write locking, data key creation.
 */
class RobustnessTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = '01JREMOTE00000000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00');
    }

    private function event(int $seq, string $table, string $id, array $payload, ?int $ageMs = 0): array
    {
        $version = Hlc::format(Hlc::wallMs() - $ageMs, $seq, self::ORIGIN);

        return ['origin' => self::ORIGIN, 'seq' => $seq, 'table' => $table, 'record_id' => $id, 'version' => $version,
            'payload' => array_merge(['id' => $id, 'created_at' => '2026-10-30 12:00:00', 'updated_at' => '2026-10-30 12:00:00'], $payload)];
    }

    private function guest(int $seq, string $id, array $extra = []): array
    {
        return $this->event($seq, 'guests', $id, array_merge(['first_name' => 'A', 'last_name' => 'B'], $extra));
    }

    public function test_poison_event_pauses_the_origin_and_keeps_earlier_events(): void
    {
        $result = app(Applier::class)->apply(self::ORIGIN, [
            $this->guest(1, '01JGUEST000000000000000001'),
            $this->guest(2, '01JGUEST000000000000000002', ['first_name' => null]), // NOT NULL column
            $this->guest(3, '01JGUEST000000000000000003'),
        ]);

        $this->assertSame('paused', $result->status);
        $this->assertStringContainsString('failed to apply seq 2', $result->reason);
        $this->assertSame(1, $result->lastSeq);
        $this->assertTrue(DB::table('guests')->where('id', '01JGUEST000000000000000001')->exists());
        $this->assertFalse(DB::table('guests')->where('id', '01JGUEST000000000000000002')->exists());
        $cursor = DB::table('peer_cursors')->where('origin', self::ORIGIN)->first();
        $this->assertSame(1, (int) $cursor->last_seq);
        $this->assertNotNull($cursor->paused_at);

        // Non-scalar values are rejected the same way.
        app(Applier::class)->unpause(self::ORIGIN);
        $result = app(Applier::class)->apply(self::ORIGIN, [$this->guest(2, '01JGUEST000000000000000002', ['first_name' => ['x']])]);
        $this->assertSame('paused', $result->status);
        $this->assertStringContainsString('not a scalar', $result->reason);
    }

    public function test_child_of_a_purged_parent_is_dropped_and_waiting_pauses_eventually(): void
    {
        // Old event (beyond the retention horizon) whose parent was purged here: dropped, not waiting.
        $old = $this->event(1, 'grant_site', '01JLINK0000000000000000001', ['grant_id' => '01JGRANTGONE00000000000000',
            'site_id' => '01JSITEGONE000000000000000'], ageMs: 400 * 86_400_000);
        $result = app(Applier::class)->apply(self::ORIGIN, [$old]);
        $this->assertSame('ok', $result->status);
        $this->assertSame(1, $result->dropped);

        // Recent event with a missing parent: waits, and pauses after the configured attempts.
        config(['portal.federation.max_wait_attempts' => 3]);
        $site = Site::create(['slug' => 'lobby', 'name' => 'Lobby', 'driver' => 'mikrotik_ros7']);
        $child = $this->event(2, 'grant_site', '01JLINK0000000000000000002', ['grant_id' => '01JGRANTLATER0000000000000',
            'site_id' => $site->id]);
        $this->assertSame('stopped', app(Applier::class)->apply(self::ORIGIN, [$child])->status);
        $this->assertSame('stopped', app(Applier::class)->apply(self::ORIGIN, [$child])->status);
        $result = app(Applier::class)->apply(self::ORIGIN, [$child]);
        $this->assertSame('paused', $result->status);
        $this->assertStringContainsString('missing parent', $result->reason);
        $this->assertStringContainsString('3 attempts', $result->reason);
    }

    public function test_waiting_pauses_after_the_time_limit(): void
    {
        $site = Site::create(['slug' => 'lobby', 'name' => 'Lobby', 'driver' => 'mikrotik_ros7']);
        $child = $this->event(1, 'grant_site', '01JLINK0000000000000000002', ['grant_id' => '01JGRANTLATER0000000000000',
            'site_id' => $site->id]);
        $this->assertSame('stopped', app(Applier::class)->apply(self::ORIGIN, [$child])->status);

        Carbon::setTestNow('2026-10-31 12:01:00');
        $child = $this->event(1, 'grant_site', '01JLINK0000000000000000002', ['grant_id' => '01JGRANTLATER0000000000000',
            'site_id' => $site->id]);
        $this->assertSame('paused', app(Applier::class)->apply(self::ORIGIN, [$child])->status);
    }

    public function test_local_tables_and_no_op_saves_cost_no_federation_queries(): void
    {
        $site = Site::create(['slug' => 'lobby', 'name' => 'Lobby', 'driver' => 'mikrotik_ros7']);
        $grant = Grant::create(['type' => GrantType::Code, 'code' => '123456', 'duration_s' => 60, 'expiry_mode' => ExpiryMode::FirstLogin]);
        app(LocalInstance::class)->id();
        $events = DB::table('federation_events')->count();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $summary = SessionSummary::create(['grant_id' => $grant->id, 'site_id' => $site->id, 'mac' => 'AA:BB:CC:DD:EE:FF', 'started_at' => now()]);
        $summary->update(['stopped_at' => now()]);
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'federation_events'))));
        $this->assertNull($summary->fresh()->version);

        $version = $grant->version;
        $grant->fresh()->save();
        $grant->save();
        $this->assertSame($version, $grant->fresh()->version);
        $this->assertSame($events, DB::table('federation_events')->count());
    }

    public function test_hlc_counter_overflow_moves_to_the_next_millisecond(): void
    {
        $hlc = app(Hlc::class);
        $wall = Hlc::wallMs();
        $hlc->receive(Hlc::format($wall, Hlc::MAX_COUNTER, self::ORIGIN));

        $next = $hlc->now();
        [$ms, $counter] = Hlc::parse($next);
        $this->assertSame([$wall + 1, 0], [$ms, $counter]);
        $this->assertGreaterThan(Hlc::format($wall, Hlc::MAX_COUNTER, self::ORIGIN), $next);
    }

    public function test_sqlite_transactions_take_the_write_lock_at_begin(): void
    {
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite.transaction_mode'));
        $file = tempnam(sys_get_temp_dir(), 'portal-lock-');
        foreach (['lock_one', 'lock_two'] as $name) {
            config(["database.connections.$name" => array_merge(config('database.connections.sqlite'),
                ['database' => $file, 'busy_timeout' => 50])]);
        }
        try {
            DB::connection('lock_one')->statement('CREATE TABLE t (n INTEGER)');
            DB::connection('lock_one')->beginTransaction();
            DB::connection('lock_one')->select('SELECT max(n) FROM t');

            // A second process cannot start a write transaction meanwhile (it would otherwise read a
            // snapshot that is stale by the time it writes: SQLITE_BUSY_SNAPSHOT).
            $this->expectException(\PDOException::class); // QueryException extends it
            DB::connection('lock_two')->transaction(fn () => DB::connection('lock_two')->select('SELECT max(n) FROM t'));
        } finally {
            DB::connection('lock_one')->rollBack();
            DB::purge('lock_one');
            DB::purge('lock_two');
            @unlink($file);
        }
    }

    public function test_data_key_exists_after_migration_and_is_not_replaced(): void
    {
        $ring = LocalSetting::get(DataKey::SETTING);
        $this->assertNotNull($ring['current']);
        $this->assertSame($ring['current'], app(DataKey::class)->currentId());
        $this->assertSame($ring, LocalSetting::get(DataKey::SETTING));
    }
}
