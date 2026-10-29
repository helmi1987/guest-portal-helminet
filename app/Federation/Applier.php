<?php

namespace App\Federation;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\DeterministicId;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Apply engine for remote events (CONCEPT.md §10.3).
 *
 * Events of one origin are applied raw in sequence order: DB upsert without model events, without a
 * new version and without a new outbox event. Rows follow last-writer-wins by HLC version (the
 * instance id in the version breaks ties); grants merge some fields. Natural-key and uniqueness
 * conflicts are resolved deterministically, so every instance reaches the same state without extra
 * events. Applied events are stored in federation_events (relay, catch-up) and the cursor of the
 * origin advances in the same transaction. Hooks run after commit.
 */
class Applier
{
    private static bool $resolving = false;

    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @var list<AppliedChange> */
    private array $changes = [];

    private ApplyResult $result;

    public function __construct(private LocalInstance $instance, private Hlc $hlc, private ApplyHooks $hooks) {}

    /** True while the engine changes a local row because of a conflict rule (version kept on purpose). */
    public static function isResolving(): bool
    {
        return self::$resolving;
    }

    /**
     * @param  iterable<array<string, mixed>|object>  $events  origin, seq, table, record_id, version, payload, proto, sig
     * @param  bool  $snapshot  initial snapshot (pairing): no resurrection check
     */
    public function apply(string $origin, iterable $events, bool $snapshot = false): ApplyResult
    {
        $this->result = $result = new ApplyResult($origin);
        $this->changes = [];
        if ($origin === $this->instance->id()) {
            $result->stop('own', 'events of this instance');

            return $result;
        }

        $batch = [];
        foreach ($events as $event) {
            $event = $this->normalise($event);
            if ($event['origin'] === $origin) {
                $batch[] = $event;
            }
        }
        usort($batch, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        DB::transaction(function () use ($origin, $batch, $snapshot, $result) {
            $cursor = DB::table('peer_cursors')->where('origin', $origin)->first();
            if ($cursor?->paused_at !== null) {
                $result->stop('paused', (string) $cursor->pause_reason);
                $result->lastSeq = (int) $cursor->last_seq;

                return;
            }
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA defer_foreign_keys = ON');
            }
            $last = (int) ($cursor->last_seq ?? 0);

            foreach ($batch as $event) {
                if ($event['seq'] <= $last) {
                    $result->skipped++;

                    continue;
                }
                if ($event['seq'] !== $last + 1) {
                    $result->stop('stopped', 'gap: expected seq '.($last + 1).", got {$event['seq']}");
                    break;
                }
                if ($event['proto'] > Federation::FEDERATION_PROTOCOL) {
                    $this->pause($origin, "protocol {$event['proto']} > ".Federation::FEDERATION_PROTOCOL, $result);
                    break;
                }
                if (! Federation::isReplicated($event['table'])) {
                    $this->pause($origin, "unknown table {$event['table']}", $result);
                    break;
                }
                try {
                    $this->hlc->receive($event['version']);
                } catch (FutureVersionException) {
                    $result->stop('stopped', "version too far in the future at seq {$event['seq']}");
                    break;
                } catch (RuntimeException $e) {
                    $this->pause($origin, "invalid event at seq {$event['seq']}: ".$e->getMessage(), $result);
                    break;
                }

                $outcome = $this->applyEvent($event, $snapshot);
                if ($outcome === 'waiting') {
                    $result->stop('stopped', "missing parent row for {$event['table']}#{$event['record_id']} at seq {$event['seq']}");
                    break;
                }
                $result->{$outcome}++;
                $this->store($event);
                $last = $event['seq'];
            }

            $result->lastSeq = $last;
            $this->saveCursor($origin, $last);
            $changes = $this->changes;
            DB::afterCommit(function () use ($changes) {
                foreach ($changes as $change) {
                    $this->hooks->dispatch($change);
                }
            });
        });

        if ($result->status !== 'ok') {
            Log::warning('Federation apply '.$result->status, ['origin' => $origin, 'reason' => $result->reason]);
        }

        return $result;
    }

    public function unpause(string $origin): void
    {
        DB::table('peer_cursors')->where('origin', $origin)->update(['paused_at' => null, 'pause_reason' => null, 'updated_at' => now()]);
    }

    /** @return 'applied'|'ignored'|'dropped'|'waiting' */
    private function applyEvent(array $event, bool $snapshot): string
    {
        $table = $event['table'];
        $id = $event['record_id'];
        $incoming = array_intersect_key($event['payload'], array_flip($this->columns($table)));
        $incoming['version'] = $event['version'];
        if ($table === 'settings' && isset($incoming['key'])) {
            $id = Setting::idFor((string) $incoming['key']);
        }
        $incoming['id'] = $id;

        $local = $this->row($table, $id);
        if ($local === null) {
            if (! $snapshot && $this->beyondHorizon($event['version'])) {
                return 'dropped'; // purged here: no resurrection
            }
            if (! $this->parentsExist($table, $incoming)) {
                // A tombstone of an unknown row without its parent carries no information.
                return ($incoming['deleted_at'] ?? null) !== null ? 'dropped' : 'waiting';
            }
            $row = $this->resolveConflicts($table, $incoming);
            DB::table($table)->insert($row);
            $this->changes[] = new AppliedChange($table, $id, null, $row, $event['origin']);

            return 'applied';
        }

        if ($event['version'] === $local['version']) {
            return 'ignored';
        }
        $incomingWins = strcmp($event['version'], (string) $local['version']) > 0;
        $row = $incomingWins ? array_merge($local, $incoming) : $local;
        if ($table === 'grants') {
            $row = self::mergeGrant($local, $incoming, $row);
        }
        if ($row == $local) {
            return 'ignored';
        }
        if (! $this->parentsExist($table, $row)) {
            return 'waiting';
        }
        $row = $this->resolveConflicts($table, $row);
        DB::table($table)->where('id', $id)->update(array_diff_key($row, ['id' => true]));
        $this->changes[] = new AppliedChange($table, $id, $local, $row, $event['origin']);

        return 'applied';
    }

    /**
     * Grant field merge on top of the last-writer-wins row: revocation is sticky (earliest),
     * first login earliest (with it the earlier expiry; a deferred expiry starts with the login),
     * extensions used maximum.
     *
     * @param  array<string, mixed>  $local
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $row  last-writer-wins result
     * @return array<string, mixed>
     */
    public static function mergeGrant(array $local, array $incoming, array $row): array
    {
        $min = fn ($a, $b) => $a === null ? $b : ($b === null ? $a : min($a, $b));
        if (array_key_exists('revoked_at', $incoming)) {
            $row['revoked_at'] = $min($local['revoked_at'] ?? null, $incoming['revoked_at']);
        }
        if (array_key_exists('extensions_used', $incoming)) {
            $row['extensions_used'] = max((int) ($local['extensions_used'] ?? 0), (int) $incoming['extensions_used']);
        }
        if (array_key_exists('first_login_at', $incoming)) {
            $l = $local['first_login_at'] ?? null;
            $i = $incoming['first_login_at'];
            if ($l !== $i && ($l !== null || $i !== null)) {
                $row['first_login_at'] = $min($l, $i);
                if ($l !== null && $i !== null) {
                    // Redeemed on two instances (partition): the earlier expiry counts.
                    $row['expires_at'] = $min($local['expires_at'] ?? null, $incoming['expires_at'] ?? null);
                } else {
                    [$redeemed, $other] = $l !== null ? [$local, $incoming] : [$incoming, $local];
                    if (($other['expires_at'] ?? null) === null) {
                        $row['expires_at'] = $redeemed['expires_at'] ?? null;
                    }
                }
            }
        }

        return $row;
    }

    /**
     * Uniqueness rules (CONCEPT.md §10.3). Returns the row to write; other local rows are changed
     * directly (version kept, deterministic on every instance).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resolveConflicts(string $table, array $row): array
    {
        return match ($table) {
            'sites' => $this->olderIdWins($table, $row, 'slug', fn (string $v, string $id) => $this->suffix($v, $id, 64)),
            'users' => $this->olderIdWins($table,
                $this->olderIdWins($table, $row, 'email', fn (string $v, string $id) => $this->suffixEmail($v, $id)),
                'oidc_subject', fn () => null),
            'mac_whitelist' => $this->newerVersionWins($row, 'mac_whitelist', ['mac' => $row['mac'] ?? null],
                fn (array $r) => ($r['deleted_at'] ?? null) === null,
                fn (array $loser, array $winner) => ['deleted_at' => $winner['created_at'] ?? $winner['updated_at']]),
            'site_codes' => $this->newerVersionWins($row, 'site_codes', ['site_id' => $row['site_id'] ?? null],
                fn (array $r) => ($r['deleted_at'] ?? null) === null && (bool) ($r['active'] ?? true) && ($r['deactivated_at'] ?? null) === null,
                fn (array $loser, array $winner) => ['active' => false, 'deactivated_at' => $winner['created_at']]),
            'devices' => $this->newerDeviceWins($row),
            default => $row,
        };
    }

    /**
     * Unique column (all rows incl. tombstones): the row with the older (smaller) ULID keeps the value,
     * the other gets $rename (suffix or null) + audit entry.
     */
    private function olderIdWins(string $table, array $row, string $column, callable $rename): array
    {
        $value = $row[$column] ?? null;
        if ($value === null) {
            return $row;
        }
        $other = DB::table($table)->where($column, $value)->where('id', '!=', $row['id'])->first();
        if ($other === null) {
            return $row;
        }
        $other = (array) $other;
        if (strcmp($row['id'], $other['id']) < 0) {
            $this->resolveLocal($table, $other, [$column => $rename($value, $other['id'])]);
            $this->conflict($table, $other['id'], $column, $row['id']);
        } else {
            $row[$column] = $rename($value, $row['id']);
            $this->conflict($table, $row['id'], $column, $other['id']);
        }

        return $row;
    }

    /**
     * At most one "active" row per group: the newer version wins; the loser gets $loserChanges.
     *
     * @param  array<string, mixed>  $where  group columns
     */
    private function newerVersionWins(array $row, string $table, array $where, callable $active, callable $loserChanges): array
    {
        if (in_array(null, $where, true) || ! $active($row)) {
            return $row;
        }
        $others = DB::table($table)->where($where)->where('id', '!=', $row['id'])->get()
            ->map(fn ($r) => (array) $r)->filter($active);
        foreach ($others as $other) {
            if (strcmp((string) $row['version'], (string) $other['version']) > 0) {
                $this->resolveLocal($table, $other, $loserChanges($other, $row));
            } else {
                $row = array_merge($row, $loserChanges($row, $other));
                $this->result->conflicts[] = "$table#{$row['id']} lost against #{$other['id']}";

                return $row;
            }
        }

        return $row;
    }

    /** "1 code = 1 active device": the newer binding (bound_at, then id) wins, the older one is unbound. */
    private function newerDeviceWins(array $row): array
    {
        if (($row['grant_id'] ?? null) === null || ($row['unbound_at'] ?? null) !== null || ($row['deleted_at'] ?? null) !== null) {
            return $row;
        }
        $others = DB::table('devices')->where('grant_id', $row['grant_id'])->where('id', '!=', $row['id'])
            ->whereNull('unbound_at')->whereNull('deleted_at')->get()->map(fn ($r) => (array) $r);
        foreach ($others as $other) {
            $rowNewer = [$row['bound_at'], $row['id']] > [$other['bound_at'], $other['id']];
            if ($rowNewer) {
                $this->resolveLocal('devices', $other, ['unbound_at' => $row['bound_at']]);
            } else {
                $row['unbound_at'] = $other['bound_at'];
                $this->result->conflicts[] = "devices#{$row['id']} unbound by newer #{$other['id']}";

                return $row;
            }
        }

        return $row;
    }

    /** Change a local row because of a conflict rule; the version stays (same result everywhere). */
    private function resolveLocal(string $table, array $before, array $changes): void
    {
        self::$resolving = true;
        try {
            DB::table($table)->where('id', $before['id'])->update($changes);
        } finally {
            self::$resolving = false;
        }
        $this->result->conflicts[] = "$table#{$before['id']} changed: ".implode(', ', array_keys($changes));
        $this->changes[] = new AppliedChange($table, $before['id'], $before, array_merge($before, $changes),
            (string) $before['origin_instance_id'], 'conflict');
    }

    /** Audit entry with a deterministic id: every instance resolving the same conflict writes one row. */
    private function conflict(string $table, string $loserId, string $column, string $winnerId): void
    {
        $this->result->conflicts[] = "$table#$loserId.$column renamed, kept by #$winnerId";
        Log::warning('Federation natural key conflict', compact('table', 'loserId', 'column', 'winnerId'));
        $id = DeterministicId::for("federation.conflict:$table:$loserId:$column");
        if (AuditLog::withTrashed()->whereKey($id)->exists()) {
            return;
        }
        (new AuditLog(['actor' => 'federation', 'action' => 'federation.conflict', 'subject_type' => $table === 'users' ? 'user' : 'site',
            'subject_id' => $loserId, 'data' => ['column' => $column, 'kept_by' => $winnerId]]))
            ->forceFill(['id' => $id])->save();
    }

    private function suffix(string $value, string $id, int $max): string
    {
        $suffix = '-dup-'.strtolower(substr($id, -6));

        return mb_substr($value, 0, $max - strlen($suffix)).$suffix;
    }

    private function suffixEmail(string $email, string $id): string
    {
        $at = strrpos($email, '@');

        return $at === false ? $this->suffix($email, $id, 255)
            : $this->suffix(substr($email, 0, $at), $id, 200).substr($email, $at);
    }

    private function parentsExist(string $table, array $row): bool
    {
        foreach (Federation::foreignKeys($table) as $column => $parent) {
            $value = $row[$column] ?? null;
            if ($value !== null && ! DB::table($parent)->where('id', $value)->exists()) {
                return false;
            }
        }

        return true;
    }

    /** Version older than the retention period: rows of that age may have been purged here. */
    private function beyondHorizon(string $version): bool
    {
        $days = max(1, (int) Setting::get('retention_days', config('portal.retention_days')));

        return Hlc::parse($version)[0] < Hlc::wallMs() - $days * 86_400_000;
    }

    private function row(string $table, string $id): ?array
    {
        $row = DB::table($table)->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return $this->columns[$table] ??= Schema::getColumnListing($table);
    }

    private function store(array $event): void
    {
        DB::table(Outbox::TABLE)->insertOrIgnore([
            'origin' => $event['origin'],
            'seq' => $event['seq'],
            'table' => $event['table'],
            'record_id' => $event['record_id'],
            'version' => $event['version'],
            'payload' => Outbox::encode($event['payload']),
            'proto' => $event['proto'],
            'sig' => $event['sig'],
            'created_at' => now(),
        ]);
    }

    private function saveCursor(string $origin, int $last): void
    {
        DB::table('peer_cursors')->upsert([['origin' => $origin, 'last_seq' => $last, 'created_at' => now(), 'updated_at' => now()]],
            ['origin'], ['last_seq', 'updated_at']);
    }

    private function pause(string $origin, string $reason, ApplyResult $result): void
    {
        DB::table('peer_cursors')->upsert([['origin' => $origin, 'paused_at' => now(), 'pause_reason' => mb_substr($reason, 0, 255),
            'created_at' => now(), 'updated_at' => now()]], ['origin'], ['paused_at', 'pause_reason', 'updated_at']);
        $result->stop('paused', $reason);
    }

    /** @return array{origin: string, seq: int, table: string, record_id: string, version: string, payload: array, proto: int, sig: ?string} */
    private function normalise(array|object $event): array
    {
        $event = (array) $event;
        $payload = $event['payload'] ?? [];

        return [
            'origin' => (string) $event['origin'],
            'seq' => (int) $event['seq'],
            'table' => (string) $event['table'],
            'record_id' => (string) $event['record_id'],
            'version' => (string) $event['version'],
            'payload' => is_string($payload) ? (array) json_decode($payload, true) : (array) $payload,
            'proto' => (int) ($event['proto'] ?? Federation::FEDERATION_PROTOCOL),
            'sig' => $event['sig'] ?? null,
        ];
    }
}
