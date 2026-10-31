<?php

namespace Tests\Feature\Federation;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Federation\Outbox;
use App\Federation\Resync;
use App\Federation\ResyncRequiredException;
use App\Models\FederationPeer;
use App\Models\Grant;
use App\Models\Instance;
use App\Models\Site;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Part K: after a restore the instance fetches its own events back from the peers before it writes
 * again, so its sequence continues after the highest one; plus the repair command.
 */
class ResyncTest extends TestCase
{
    use BuildsFederation, FederatedHttp, RefreshDatabase, SimulatesInstances;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFederation();
        $this->marker = sys_get_temp_dir().'/portal-test-'.getmypid().'-manifest-'.bin2hex(random_bytes(3)).'.json';
        config(['portal.backup.restore_marker' => $this->marker]);
    }

    protected function tearDown(): void
    {
        foreach (glob(preg_replace('/\.json$/', '', $this->marker).'*') ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** @return array<string, list<array>> all rows of the simulated instance (a "backup") */
    private function dump(string $name): array
    {
        return $this->on($name, function () {
            $tables = [];
            foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE '\\_%' ESCAPE '\\'") as $t) {
                $tables[$t->name] = array_map(fn ($r) => (array) $r, DB::table($t->name)->get()->all());
            }

            return $tables;
        });
    }

    private function restore(string $name, array $dump): void
    {
        $this->on($name, function () use ($dump) {
            DB::statement('PRAGMA foreign_keys = OFF');
            foreach ($dump as $table => $rows) {
                DB::table($table)->delete();
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
            DB::statement('PRAGMA foreign_keys = ON');
        });
    }

    private function ownSeq(string $name): int
    {
        return $this->on($name, fn () => (int) DB::table(Outbox::TABLE)->where('origin', app(LocalInstance::class)->id())->max('seq'));
    }

    private function grantOn(string $name, string $code): Grant
    {
        return $this->on($name, fn () => Grant::create(['type' => GrantType::Code, 'code' => $code, 'duration_s' => 60,
            'expiry_mode' => ExpiryMode::FirstLogin]));
    }

    public function test_restore_fetches_own_events_back_before_writing(): void
    {
        $this->withoutFederationWriteGuard();
        $site = $this->on('a', fn () => Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7']));
        $this->syncRound();
        $backup = $this->dump('a');

        // Written after the backup and replicated.
        $lost = $this->grantOn('a', '111111');
        $this->on('a', fn () => Site::findOrFail($site->id)->update(['name' => 'Site A renamed']));
        $this->syncRound();
        $highest = $this->ownSeq('a');
        $this->assertNotNull($this->rowOn('b', 'grants', $lost->id));

        // Restore: the manifest in the data folder marks it at the next start.
        $this->restore('a', $backup);
        file_put_contents($this->marker, '{}');
        $this->on('a', fn () => Artisan::call('portal:init'));
        $this->assertTrue($this->on('a', fn () => Resync::required()));
        $this->assertFileDoesNotExist($this->marker);
        $this->assertLessThan($highest, $this->ownSeq('a'));

        // No writes of replicated data meanwhile.
        $this->on('a', function () {
            try {
                Grant::create(['type' => GrantType::Code, 'code' => '222222', 'duration_s' => 60, 'expiry_mode' => ExpiryMode::FirstLogin]);
                $this->fail('write accepted during the resync');
            } catch (ResyncRequiredException $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
            $this->assertSame(0, Grant::where('code', '222222')->count());
        });

        // The hub down: the block stays.
        $this->down = ['hub'];
        $this->on('a', fn () => Artisan::call('portal:federation:sync'));
        $this->assertTrue($this->on('a', fn () => Resync::required()));

        // Hub back: own events recovered, sequence continues after the highest one.
        $this->down = [];
        $this->on('a', fn () => Artisan::call('portal:federation:sync'));
        $this->assertFalse($this->on('a', fn () => Resync::required()));
        $this->assertSame($highest, $this->ownSeq('a'));
        $this->assertNotNull($this->rowOn('a', 'grants', $lost->id));
        $this->assertSame('Site A renamed', $this->rowOn('a', 'sites', $site->id)['name']);

        $next = $this->grantOn('a', '333333');
        $this->assertSame($highest + 1, $this->ownSeq('a'));
        $this->syncRound();
        $this->assertNotNull($this->rowOn('b', 'grants', $next->id));
    }

    public function test_restore_without_manifest_is_detected_by_the_peers(): void
    {
        $this->withoutFederationWriteGuard();
        $backup = $this->dump('b');
        $lost = $this->grantOn('b', '444444');
        $this->syncRound();
        $this->restore('b', $backup);
        $this->assertNull($this->rowOn('b', 'grants', $lost->id));

        // The hub has own events of b beyond b's sequence: resync in the same run.
        $this->on('b', fn () => Artisan::call('portal:federation:sync'));
        $this->assertFalse($this->on('b', fn () => Resync::required()));
        $this->assertNotNull($this->rowOn('b', 'grants', $lost->id));
    }

    public function test_repair_command(): void
    {
        $this->withoutFederationWriteGuard();
        $idHub = $this->idOf('hub');
        $this->on('a', function () use ($idHub) {
            $this->assertSame(0, Artisan::call('portal:federation:repair', ['action' => 'status']));
            $this->assertStringContainsString('Hub', Artisan::output());

            // resume
            FederationPeer::query()->whereKey($idHub)->update(['paused_at' => now(), 'pause_reason' => 'clock skew 400 s']);
            $this->assertSame(0, Artisan::call('portal:federation:repair', ['action' => 'resume', 'instance' => 'Hub']));
            $this->assertNull(FederationPeer::findOrFail($idHub)->paused_at);

            // repin needs a valid pin
            $this->assertSame(1, Artisan::call('portal:federation:repair', ['action' => 'repin', 'instance' => $idHub, '--pin' => 'nope']));
            $old = Instance::findOrFail($idHub)->cert_fingerprint;
            $new = 'sha256//'.base64_encode(random_bytes(32));
            $this->assertSame(0, Artisan::call('portal:federation:repair', ['action' => 'repin', 'instance' => $idHub, '--pin' => $new]));
            $this->assertSame($new, Instance::findOrFail($idHub)->cert_fingerprint);
            DB::table('instances')->where('id', $idHub)->update(['cert_fingerprint' => $old]);

            // reset-cursor
            $this->assertSame(0, Artisan::call('portal:federation:repair', ['action' => 'reset-cursor', 'instance' => $idHub]));
            $this->assertSame(0, (int) DB::table('peer_cursors')->where('origin', $idHub)->value('last_seq'));
            $this->assertNull(FederationPeer::findOrFail($idHub)->acks);

            // unknown action / instance
            $this->assertSame(1, Artisan::call('portal:federation:repair', ['action' => 'nope']));
            $this->assertSame(1, Artisan::call('portal:federation:repair', ['action' => 'resume', 'instance' => 'Nobody']));
        });
        // Applying again after the reset is idempotent and catches up.
        $this->syncRound();
        $this->assertSame($this->on('hub', fn () => (int) DB::table(Outbox::TABLE)->where('origin', $idHub)->max('seq')),
            $this->on('a', fn () => (int) DB::table('peer_cursors')->where('origin', $idHub)->value('last_seq')));

        // resync / skip-resync
        $this->on('a', function () {
            $this->assertSame(0, Artisan::call('portal:federation:repair', ['action' => 'resync']));
            $this->assertTrue(Resync::required());
            $this->assertSame(1, Artisan::call('portal:federation:repair', ['action' => 'skip-resync']));
            $this->assertTrue(Resync::required());
            $this->assertSame(0, Artisan::call('portal:federation:repair', ['action' => 'skip-resync', '--force' => true]));
            $this->assertFalse(Resync::required());
        });
    }

    public function test_skip_survives_heartbeats_and_continues_above_the_peers_sequence(): void
    {
        $this->withoutFederationWriteGuard();
        $idA = $this->idOf('a');
        $this->grantOn('a', '555555');
        $this->syncRound();
        $backup = $this->dump('a');
        $this->grantOn('a', '666666');
        $this->grantOn('a', '777777');
        $this->syncRound();
        $highest = $this->ownSeq('a');

        // Restore; the hub delivers an own event that cannot be verified: recovery fails for good.
        $this->restore('a', $backup);
        $this->on('hub', fn () => DB::table(Outbox::TABLE)->where('origin', $idA)->where('seq', $highest)
            ->update(['sig' => base64_encode(random_bytes(64))]));
        $this->on('a', fn () => Artisan::call('portal:federation:sync'));
        $this->assertTrue($this->on('a', fn () => Resync::required()));
        $this->assertLessThan($highest, $this->ownSeq('a'));

        // Skip: the hub's report is stored, the sequence continues above it.
        $this->on('a', function () use ($highest) {
            $this->assertSame(0, Artisan::call('portal:federation:repair', ['action' => 'skip-resync', '--force' => true]));
            // The audit entry of the skip is the first new own event.
            $this->assertStringContainsString('continues at '.($highest + 2), Artisan::output());
            $this->assertSame([$this->idOf('hub') => $highest], Resync::skipped());
            $this->assertSame($highest + 1, (int) DB::table(Outbox::TABLE)->where('origin', app(LocalInstance::class)->id())
                ->where('table', 'audit_logs')->max('seq'));
            $this->assertSame(0, Artisan::call('portal:federation:repair', ['action' => 'status']));
            $this->assertStringContainsString("up to $highest", Artisan::output());
        });

        // The next heartbeat reports the same sequence: no new resync.
        $this->on('a', fn () => Artisan::call('portal:federation:sync'));
        $this->assertFalse($this->on('a', fn () => Resync::required()));

        // New own events do not reuse sequence numbers and reach the others.
        $next = $this->grantOn('a', '888888');
        $this->assertSame($highest + 2, $this->on('a', fn () => (int) DB::table(Outbox::TABLE)
            ->where('origin', $idA)->where('record_id', $next->id)->value('seq')));
        $this->syncRound();
        $this->assertFalse($this->on('a', fn () => Resync::required()));
        $this->assertNotNull($this->rowOn('hub', 'grants', $next->id));
        $this->assertNotNull($this->rowOn('b', 'grants', $next->id));

        // A peer reporting own events beyond everything used here marks the resync again.
        $this->on('a', function () use ($idA, $highest) {
            $resync = app(Resync::class);
            $hub = Instance::findOrFail($this->idOf('hub'));
            $resync->checkPeer($hub, [$idA => $highest + 2]);
            $this->assertFalse(Resync::required());
            $resync->checkPeer($hub, [$idA => $highest + 3]);
            $this->assertTrue(Resync::required());
        });
    }
}
