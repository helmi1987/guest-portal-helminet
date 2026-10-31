<?php

namespace App\Federation;

use App\Models\FederationPeer;
use App\Models\Instance;
use App\Models\LocalSetting;
use App\Support\LocalInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resync after a restore (CONCEPT.md §10.3, part K). A restored database lacks the own events written
 * after the backup, while the peers have them: new own events would reuse their sequence numbers.
 * Restore detected (backup manifest in the data folder at start, or a peer reports own events beyond
 * the local sequence): the local setting federation_resync_required blocks every write of a replicated
 * row (Outbox::record()); the sync run fetches the own events back from every direct peer (signature
 * checked with the own key, applied like remote events), and once every direct peer was caught up the
 * block is lifted, so the own sequence continues after the highest one. A peer that stays unreachable
 * keeps the block; the admin can lift it on the command line (portal:federation:repair skip-resync).
 * A skip stores per direct peer the own sequence it reported (federation_resync_skipped): the own
 * sequence continues above the highest of them (no reuse of numbers a peer has seen), and a peer only
 * starts the resync again when it later reports own events beyond that.
 */
class Resync
{
    public const SETTING = 'federation_resync_required';

    /** Per direct peer: own sequence it reported when the resync was skipped (peer id => seq). */
    public const SKIPPED_SETTING = 'federation_resync_skipped';

    public function __construct(
        private LocalInstance $instance,
        private PeerClient $client,
        private Applier $applier,
        private SigningKey $signer,
    ) {}

    public static function required(): bool
    {
        return LocalSetting::get(self::SETTING) !== null;
    }

    /** @return array{since: string, reason: string, done: list<string>}|null */
    public static function state(): ?array
    {
        $state = LocalSetting::get(self::SETTING);

        return is_array($state) ? $state + ['done' => []] : null;
    }

    public static function mark(string $reason): void
    {
        if (self::required()) {
            return;
        }
        LocalSetting::put(self::SETTING, ['since' => now()->toIso8601String(), 'reason' => mb_substr($reason, 0, 255), 'done' => []]);
        Log::warning('Federation resync required: writes of replicated data are blocked until the own events are back',
            ['reason' => $reason]);
    }

    public static function clear(string $why): void
    {
        LocalSetting::query()->whereKey(self::SETTING)->delete();
        Log::warning('Federation resync finished', ['how' => $why]);
    }

    /** @return array<string, int> peer id => own sequence it reported when the resync was skipped */
    public static function skipped(): array
    {
        $skipped = LocalSetting::get(self::SKIPPED_SETTING);

        return is_array($skipped) ? array_map('intval', $skipped) : [];
    }

    /** Lowest value the own sequence continues from (highest own sequence skipped, 0 without a skip). */
    public static function seqFloor(): int
    {
        return max([0, ...array_values(self::skipped())]);
    }

    /**
     * Lift the block without the missing own events (repair command, admin decision): remember what
     * every direct peer reported, so the sequence continues above it and those reports do not mark
     * the resync again. Values only grow (an earlier, higher report is kept).
     *
     * @return array<string, int> the stored skip values
     */
    public function skip(): array
    {
        $own = $this->instance->id();
        $skipped = self::skipped();
        foreach (FederationPeer::query()->get() as $state) {
            $seq = (int) ($state->origins[$own] ?? 0);
            if ($seq > 0) {
                $skipped[$state->instance_id] = max($seq, $skipped[$state->instance_id] ?? 0);
            }
        }
        DB::transaction(function () use ($skipped) {
            if ($skipped !== []) {
                LocalSetting::put(self::SKIPPED_SETTING, $skipped);
            }
            self::clear('skipped on the command line, sequence continues at '.(Outbox::lastSeq($this->instance->id()) + 1));
        });

        return $skipped;
    }

    /** Highest own sequence used so far (stored here, or skipped after a restore). */
    public function ownSeq(): int
    {
        return Outbox::lastSeq($this->instance->id());
    }

    /**
     * Restore detection by a peer's heartbeat: it stores own events beyond the local sequence.
     *
     * @param  array<string, int>  $origins  peer's highest stored sequence per origin
     */
    public function checkPeer(Instance $peer, array $origins): void
    {
        $theirs = (int) ($origins[$this->instance->id()] ?? 0);
        // ownSeq() includes the skipped sequences: a report up to a skipped value is no new restore.
        if ($theirs > ($own = $this->ownSeq())) {
            self::mark("peer {$peer->name} has own events up to seq $theirs, local $own (restored backup?)");
        }
    }

    /**
     * Fetch own events from one direct peer (after its heartbeat); marks the peer done when nothing of
     * the own origin is missing any more.
     *
     * @return int events applied
     */
    public function recoverFrom(Instance $peer, FederationPeer $state): int
    {
        $origin = $this->instance->id();
        $max = (int) ($state->origins[$origin] ?? 0);
        $publicKey = $this->signer->publicKey();
        $applied = 0;
        while (($after = $this->ownSeq()) < $max) {
            $response = $this->client->to($peer)->get('/federation/events', [
                'origin' => $origin, 'after' => $after, 'limit' => (int) config('portal.federation.batch', 500)]);
            $events = $response->successful() ? $response->json('events') : null;
            if (! is_array($events) || $events === []) {
                throw new RuntimeException('own events from '.$peer->name.': status '.$response->status());
            }
            $verified = [];
            foreach ($events as $event) {
                $wire = EventCodec::toWire((array) $event);
                if ($wire['origin'] !== $origin || ! EventCodec::verify($wire, $publicKey)) {
                    throw new RuntimeException("own event at seq {$wire['seq']} from {$peer->name} has an invalid signature");
                }
                $verified[] = $wire;
            }
            $result = $this->applier->recoverOwn($verified);
            $applied += $result->applied;
            if ($result->status !== 'ok' || $result->lastSeq <= $after) {
                throw new RuntimeException("own events from {$peer->name}: {$result->status} {$result->reason}");
            }
        }
        $this->markDone($peer->id);

        return $applied;
    }

    /** Lift the block once every direct peer was caught up (also: no direct peer at all). */
    public function completeIfDone(array $directPeerIds): bool
    {
        $state = self::state();
        if ($state === null) {
            return true;
        }
        if (array_diff($directPeerIds, $state['done']) !== []) {
            return false;
        }
        self::clear('own events recovered from '.(count($directPeerIds) ?: 'no').' peer(s), sequence continues at '.($this->ownSeq() + 1));

        return true;
    }

    private function markDone(string $peerId): void
    {
        $state = self::state();
        if ($state !== null && ! in_array($peerId, $state['done'], true)) {
            $state['done'][] = $peerId;
            LocalSetting::put(self::SETTING, $state);
        }
    }
}
