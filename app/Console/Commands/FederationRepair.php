<?php

namespace App\Console\Commands;

use App\Federation\Applier;
use App\Federation\Outbox;
use App\Federation\PeerCertificate;
use App\Federation\Resync;
use App\Federation\ResyncRequiredException;
use App\Federation\Topology;
use App\Models\FederationPeer;
use App\Models\Instance;
use App\Support\Audit;
use App\Support\LocalInstance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repair tools for the federation (docs/federation.md, CONCEPT.md §10.2), for cases the admin UI does
 * not cover or cannot reach (e.g. writes blocked by the restore resync):
 *
 * - status: instances, transport and apply state, cursors, resync state;
 * - resume <instance>: lift the transport pause and the apply pause of that origin;
 * - repin <instance> --pin=sha256//...: trust a new certificate of a peer (e.g. its federation key was
 *   lost and regenerated); local only, the peer's own instance event confirms it later;
 * - reset-cursor <instance> [--seq=0]: apply that origin's events again from a sequence (applying is
 *   idempotent: older or equal versions change nothing) and let a direct peer's acknowledgements be
 *   learnt anew (push from its next heartbeat);
 * - resync: fetch the own events back from the peers (as after a restore), writes blocked meanwhile;
 * - skip-resync --force: lift the resync block without the own events of every peer. Only when the
 *   missing peers are gone for good: own events another peer still has would collide with new ones.
 */
class FederationRepair extends Command
{
    protected $signature = 'portal:federation:repair
    {action : status | resume | repin | reset-cursor | resync | skip-resync}
    {instance? : Instance id or name (resume, repin, reset-cursor)}
    {--pin= : New certificate pin sha256//<base64> (repin), from the federation page of that instance}
    {--seq=0 : Sequence to apply again after (reset-cursor)}
    {--force : Confirm skip-resync}';

    protected $description = 'Inspect and repair the federation state of this instance';

    public function handle(LocalInstance $local, Topology $topology, Applier $applier): int
    {
        $action = (string) $this->argument('action');
        if ($action === 'status') {
            return $this->status($local, $topology);
        }
        if ($action === 'resync') {
            Resync::mark('requested on the command line');
            $this->audit('federation.resync_requested', null);
            $this->info('Resync required: the next sync run fetches the own events from the peers; replicated data is read-only until then.');

            return self::SUCCESS;
        }
        if ($action === 'skip-resync') {
            if (! Resync::required()) {
                $this->info('No resync pending.');

                return self::SUCCESS;
            }
            if (! $this->option('force')) {
                $this->error('Own events written after the backup that a peer still has would collide with new ones. '
                    .'Only skip when those peers are removed for good. Repeat with --force.');

                return self::FAILURE;
            }
            Resync::clear('skipped on the command line');
            $this->audit('federation.resync_skipped', null);
            $this->warn('Resync block lifted; the own sequence continues at '.((int) DB::table(Outbox::TABLE)->where('origin', $local->id())->max('seq') + 1).'.');

            return self::SUCCESS;
        }
        if (! in_array($action, ['resume', 'repin', 'reset-cursor'], true)) {
            $this->error("Unknown action $action.");

            return self::FAILURE;
        }

        $instance = $this->instance((string) $this->argument('instance'));
        if ($instance === null) {
            $this->error('Unknown instance (give its id or name; see the status action).');

            return self::FAILURE;
        }
        if ($instance->id === $local->id()) {
            $this->error('That is this instance.');

            return self::FAILURE;
        }

        return match ($action) {
            'resume' => $this->resume($instance, $applier),
            'repin' => $this->repin($instance),
            'reset-cursor' => $this->resetCursor($instance, $topology),
        };
    }

    private function status(LocalInstance $local, Topology $topology): int
    {
        $direct = $topology->directPeers()->pluck('id')->all();
        $states = FederationPeer::query()->get()->keyBy('instance_id');
        $cursors = DB::table('peer_cursors')->get()->keyBy('origin');
        $ownSeq = (int) DB::table(Outbox::TABLE)->where('origin', $local->id())->max('seq');
        $this->line("This instance: {$local->id()}, own sequence $ownSeq, hub ".($topology->hubId() ?? 'not set')
            .($topology->isHub() ? ' (this instance)' : ''));
        if (($resync = Resync::state()) !== null) {
            $this->warn("Resync required since {$resync['since']}: {$resync['reason']}; done with: ".(implode(', ', $resync['done']) ?: 'none'));
        }
        $rows = [];
        foreach (Instance::withTrashed()->orderBy('name')->get() as $instance) {
            if ($instance->id === $local->id()) {
                continue;
            }
            $state = $states->get($instance->id);
            $cursor = $cursors->get($instance->id);
            $rows[] = [
                $instance->name,
                $instance->id,
                $instance->trashed() ? 'removed' : (in_array($instance->id, $direct, true) ? 'direct' : 'via hub'),
                $state?->last_seen_at?->toIso8601String() ?? '-',
                $state?->paused_at ? 'paused: '.$state->pause_reason : ($state?->last_error ?? 'ok'),
                ($cursor->last_seq ?? 0).' / '.($state?->origins[$instance->id] ?? '?'),
                $cursor?->paused_at ? 'paused: '.$cursor->pause_reason : ($cursor?->blocked_seq ? 'waiting at '.$cursor->blocked_seq : 'ok'),
                $state?->ack($local->id()) ?? '-',
            ];
        }
        $this->table(['Name', 'Id', 'Link', 'Last contact', 'Transport', 'Applied / at peer', 'Apply', 'Own acked'], $rows);

        return self::SUCCESS;
    }

    private function resume(Instance $instance, Applier $applier): int
    {
        FederationPeer::query()->whereKey($instance->id)->update(['paused_at' => null, 'pause_reason' => null]);
        $applier->unpause($instance->id);
        $this->audit('federation.peer_unpaused', $instance);
        $this->info("Resumed {$instance->name}.");

        return self::SUCCESS;
    }

    private function repin(Instance $instance): int
    {
        $pin = (string) $this->option('pin');
        if (! PeerCertificate::isPin($pin)) {
            $this->error('Give the new pin with --pin=sha256//<base64> (federation page of that instance).');

            return self::FAILURE;
        }
        // Local trust decision without an event: only the instance itself publishes its pin.
        DB::table('instances')->where('id', $instance->id)->update(['cert_fingerprint' => $pin]);
        FederationPeer::query()->whereKey($instance->id)->update(['last_error' => null]);
        Cache::forget('federation:live-down:'.$instance->id);
        Log::warning('Federation peer re-pinned on the command line', ['peer' => $instance->id, 'old' => $instance->cert_fingerprint, 'new' => $pin]);
        $this->audit('federation.peer_repinned', $instance, ['pin' => $pin]);
        $this->info("New pin for {$instance->name} stored; the next sync run uses it.");

        return self::SUCCESS;
    }

    private function resetCursor(Instance $instance, Topology $topology): int
    {
        $seq = max(0, (int) $this->option('seq'));
        DB::table('peer_cursors')->updateOrInsert(['origin' => $instance->id], ['last_seq' => $seq, 'paused_at' => null,
            'pause_reason' => null, 'blocked_seq' => null, 'blocked_since' => null, 'blocked_attempts' => 0,
            'updated_at' => now(), 'created_at' => now()]);
        if ($topology->isDirectPeer($instance->id)) {
            // What it has is learnt again from its next heartbeat.
            FederationPeer::query()->whereKey($instance->id)->update(['acks' => null, 'origins' => null]);
        }
        $this->audit('federation.cursor_reset', $instance, ['seq' => $seq]);
        $this->info("Events of {$instance->name} are applied again after sequence $seq with the next sync run.");

        return self::SUCCESS;
    }

    private function instance(string $key): ?Instance
    {
        if ($key === '') {
            return null;
        }

        return Instance::withTrashed()->find($key) ?? Instance::query()->where('name', $key)->first();
    }

    /** Audit entry (replicated); while the resync blocks writes, the application log has it. */
    private function audit(string $action, ?Instance $subject, array $data = []): void
    {
        try {
            Audit::log($action, $subject, $data);
        } catch (ResyncRequiredException) {
            Log::warning('Federation repair (not in the audit log: resync in progress)', ['action' => $action,
                'instance' => $subject?->id] + $data);
        }
    }
}
