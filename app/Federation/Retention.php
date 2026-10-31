<?php

namespace App\Federation;

use App\Models\FederationPeer;
use App\Models\Setting;
use App\Support\Hlc;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Retention of replicated data (CONCEPT.md §10.3, part J). The purge horizon is the retention period:
 * the apply engine drops events for unknown rows older than it (no resurrection). Hence
 *
 * - a replicated row is purged locally only when its last change (version) is beyond the horizon;
 * - a tombstone is hard-deleted only beyond the horizon, after every direct peer acknowledged its
 *   event (star: the hub keeps the events until all instances have them), and only when no
 *   replicated child row referring to it changed within the horizon (those go with it by cascade);
 * - the change log keeps an event until it is beyond the horizon, acknowledged by every direct peer,
 *   no longer the current version of a stored row (uniqueness rules and snapshots read those) and not
 *   the highest sequence of its origin (sequence numbers and the clock continue from it).
 *
 * All of this is local: purging writes no events. A direct peer without acknowledgements (never
 * reached, or down for longer than the retention period) holds back tombstone and log purges until it
 * catches up or is removed from the federation.
 */
class Retention
{
    /** Tables whose tombstones stay: a removed instance must stay known as removed. */
    private const KEEP_TOMBSTONES = ['instances'];

    public function __construct(private Topology $topology) {}

    public static function days(): int
    {
        return max(1, (int) Setting::get('retention_days', config('portal.retention_days')));
    }

    /** Versions sorting before this string are beyond the purge horizon. */
    public static function horizonVersion(): string
    {
        return sprintf('%013d', Hlc::wallMs() - self::days() * 86_400_000);
    }

    /**
     * Highest sequence of an origin that every direct peer has applied (null: no direct peer, i.e. no
     * federation; 0: some peer has not acknowledged anything of it).
     *
     * @return array<string, int|null> origin => bound
     */
    public function ackBounds(): array
    {
        $peers = $this->topology->directPeers();
        $states = FederationPeer::query()->whereIn('instance_id', $peers->pluck('id'))->get()->keyBy('instance_id');
        $bounds = [];
        foreach (DB::table(Outbox::TABLE)->distinct()->pluck('origin') as $origin) {
            $bound = null;
            foreach ($peers as $peer) {
                $ack = $states->get($peer->id)?->ack($origin) ?? 0;
                $bound = $bound === null ? $ack : min($bound, $ack);
            }
            $bounds[$origin] = $bound;
        }

        return $bounds;
    }

    /** True when the event of this row version is acknowledged by every direct peer (or no longer stored). */
    public function acknowledged(string $table, string $id, string $version, ?array $bounds = null): bool
    {
        $bounds ??= $this->ackBounds();
        $event = DB::table(Outbox::TABLE)->where('table', $table)->where('record_id', $id)->where('version', $version)
            ->first(['origin', 'seq']);
        if ($event === null) {
            return true;
        }
        $bound = $bounds[$event->origin] ?? null;

        return $bound === null || (int) $event->seq <= $bound;
    }

    /**
     * Hard-deletes tombstones beyond the horizon that every direct peer has (children first).
     *
     * @return array<string, int> table => purged rows
     */
    public function purgeTombstones(): array
    {
        $horizon = self::horizonVersion();
        $bounds = $this->ackBounds();
        $purged = [];
        foreach (array_reverse(array_keys(Federation::TABLES)) as $table) {
            if (in_array($table, self::KEEP_TOMBSTONES, true) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            $children = $this->childReferences($table);
            $count = 0;
            DB::table($table)->whereNotNull('deleted_at')->where('version', '<', $horizon)->orderBy('id')
                ->select(['id', 'version'])->chunk(500, function ($rows) use ($table, $children, $bounds, $horizon, &$count) {
                    foreach ($rows as $row) {
                        if (! $this->acknowledged($table, $row->id, (string) $row->version, $bounds)) {
                            continue;
                        }
                        // Child rows go with it (foreign key cascade), but only rows unchanged within the horizon.
                        foreach ($children as [$child, $column]) {
                            if (DB::table($child)->where($column, $row->id)->where('version', '>=', $horizon)->exists()) {
                                continue 2;
                            }
                        }
                        $count += DB::table($table)->where('id', $row->id)->whereNotNull('deleted_at')->delete();
                    }
                });
            if ($count > 0) {
                $purged[$table] = $count;
            }
        }
        if ($purged !== []) {
            Log::info('Federation: tombstones purged', $purged);
        }

        return $purged;
    }

    /**
     * Deletes change log events nobody needs any more (see class comment).
     *
     * @return int deleted events
     */
    public function compactLog(): int
    {
        $horizon = self::horizonVersion();
        $deleted = 0;
        $max = DB::table(Outbox::TABLE)->groupBy('origin')->selectRaw('origin, max(seq) as seq')->pluck('seq', 'origin');
        foreach ($this->ackBounds() as $origin => $bound) {
            $upTo = min($bound ?? PHP_INT_MAX, (int) ($max[$origin] ?? 0) - 1);
            if ($upTo < 1) {
                continue;
            }
            foreach (array_keys(Federation::TABLES) as $table) {
                $deleted += DB::table(Outbox::TABLE)
                    ->where('origin', $origin)->where('seq', '<=', $upTo)->where('table', $table)
                    ->where('version', '<', $horizon)
                    ->whereNotExists(fn ($q) => $q->from($table)->whereColumn("$table.id", Outbox::TABLE.'.record_id')
                        ->whereColumn("$table.version", Outbox::TABLE.'.version'))
                    ->delete();
            }
            // Events of tables no longer replicated (older protocol) are not needed either.
            $deleted += DB::table(Outbox::TABLE)->where('origin', $origin)->where('seq', '<=', $upTo)
                ->where('version', '<', $horizon)->whereNotIn('table', array_keys(Federation::TABLES))->delete();
        }
        if ($deleted > 0) {
            Log::info('Federation: change log compacted', ['events' => $deleted]);
        }

        return $deleted;
    }

    /** Expired or used join tokens (local). */
    public function purgeJoinTokens(): int
    {
        return DB::table('federation_join_tokens')->where(fn ($q) => $q->where('expires_at', '<', now()->subDay())
            ->orWhere('used_at', '<', now()->subDay()))->delete();
    }

    /**
     * Replicated tables with an enforced foreign key to $table.
     *
     * @return list<array{0: string, 1: string}> child table, column
     */
    private function childReferences(string $table): array
    {
        $children = [];
        foreach (Federation::TABLES as $child => $keys) {
            foreach ($keys as $column => $parent) {
                if ($parent === $table) {
                    $children[] = [$child, $column];
                }
            }
        }

        return $children;
    }
}
