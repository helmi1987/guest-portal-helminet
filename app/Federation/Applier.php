<?php

namespace App\Federation;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Radius\ClientsFile;
use App\Support\DeterministicId;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
    /** Columns of an instance row only that instance may change (CONCEPT.md §10.6). */
    public const INSTANCE_TRUST_COLUMNS = ['signing_public_key', 'cert_fingerprint', 'federation_url', 'admin_url'];

    private static bool $resolving = false;

    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @var list<AppliedChange> */
    private array $changes = [];

    private ApplyResult $result;

    /** Recovering own events (no cursor, no pause state for the own origin). */
    private bool $own = false;

    /** Re-applying the new hub's user events at re-pairing (reapplyHubUsers()): no security authority check. */
    private bool $trusted = false;

    /** TOTP steps (30 s) a replicated `totp_last_step` may lie ahead of the current step (clock skew). */
    private const TOTP_STEP_MARGIN = 10;

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
        return $this->run($origin, $events, $snapshot, false);
    }

    /**
     * Own events fetched back from a peer after a restore (Resync, CONCEPT.md §10.3): applied like
     * remote events after the highest own sequence stored here (signatures checked by the caller).
     *
     * @param  iterable<array<string, mixed>|object>  $events
     */
    public function recoverOwn(iterable $events): ApplyResult
    {
        return $this->run($this->instance->id(), $events, false, true);
    }

    private function run(string $origin, iterable $events, bool $snapshot, bool $own): ApplyResult
    {
        $this->result = $result = new ApplyResult($origin);
        $this->changes = [];
        $this->own = $own;
        if ($origin === $this->instance->id() && ! $own) {
            $result->stop('own', 'events of this instance');

            return $result;
        }

        try {
            $batch = [];
            foreach ($events as $event) {
                $event = $this->normalise($event);
                if ($event['origin'] === $origin) {
                    $batch[] = $event;
                }
            }
        } catch (Throwable $e) {
            DB::transaction(fn () => $this->pause($origin, 'malformed event: '.$e->getMessage(), $result));
            Log::error('Federation apply paused', ['origin' => $origin, 'reason' => $result->reason]);

            return $result;
        }
        usort($batch, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        DB::transaction(function () use ($origin, $batch, $snapshot, $result, $own) {
            // Own events have no cursor: they continue after the highest own sequence stored here.
            $cursor = $own ? null : DB::table('peer_cursors')->where('origin', $origin)->first();
            if ($cursor?->paused_at !== null) {
                $result->stop('paused', (string) $cursor->pause_reason);
                $result->lastSeq = (int) $cursor->last_seq;

                return;
            }
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA defer_foreign_keys = ON');
            }
            $last = $own ? Outbox::lastSeq($origin) : (int) ($cursor->last_seq ?? 0);

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

                // Savepoint per event: a poison event (wrong types, missing NOT NULL value, ...) is rolled
                // back alone and pauses the origin instead of failing the batch forever.
                $changeCount = count($this->changes);
                try {
                    $outcome = DB::transaction(fn () => $this->applyEvent($event, $snapshot));
                } catch (Throwable $e) {
                    array_splice($this->changes, $changeCount);
                    $this->pause($origin, "failed to apply seq {$event['seq']} ({$event['table']}#{$event['record_id']}): "
                        .mb_substr($e->getMessage(), 0, 150), $result);
                    break;
                }
                if ($outcome === 'waiting') {
                    $this->waitForParent($origin, $cursor, $event, $result);
                    break;
                }
                $result->{$outcome}++;
                $this->store($event);
                $last = $event['seq'];
            }

            $result->lastSeq = $last;
            if (! $own) {
                $this->saveCursor($origin, $last);
            }
            $changes = $this->changes;
            DB::afterCommit(function () use ($changes) {
                foreach ($changes as $change) {
                    $this->hooks->dispatch($change);
                }
            });
        });

        if ($result->status !== 'ok') {
            Log::log($result->status === 'paused' ? 'error' : 'warning', 'Federation apply '.$result->status,
                ['origin' => $origin, 'reason' => $result->reason]);
        }

        return $result;
    }

    /**
     * Initial state from the hub at pairing (CONCEPT.md §10.2): all rows of the replicated tables in
     * parent-before-child order, written raw with last-writer-wins against local rows (a fresh
     * instance has none but its own instance row), the stored events of the rows' current versions
     * (base states for the conflict rules) and the hub's cursors, so the sync continues after them.
     *
     * @param  array{tables?: array<string, list<array>>, events?: list<array>, cursors?: array<string, int>}  $snapshot
     * @return int rows written
     */
    public function applySnapshot(array $snapshot): int
    {
        $local = $this->instance->id();
        $written = 0;
        DB::transaction(function () use ($snapshot, $local, &$written) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA defer_foreign_keys = ON');
            }
            $max = null;
            foreach (array_keys(Federation::TABLES) as $table) {
                $columns = array_flip($this->columns($table));
                foreach ($snapshot['tables'][$table] ?? [] as $row) {
                    $row = array_diff_key(array_intersect_key((array) $row, $columns), array_flip(Federation::localColumns($table)));
                    foreach ($row as $column => $value) {
                        if (is_array($value) || is_object($value)) {
                            throw new RuntimeException("snapshot $table.$column is not a scalar");
                        }
                    }
                    if (! isset($row['id'], $row['version']) || ! is_string($row['version'])) {
                        throw new RuntimeException("snapshot row of $table without id or version");
                    }
                    Hlc::parse($row['version']);
                    $current = $this->row($table, (string) $row['id']);
                    if ($current !== null && strcmp((string) $current['version'], $row['version']) >= 0) {
                        continue;
                    }
                    $this->put($table, $row, $current === null);
                    $written++;
                    $max = $max === null || strcmp($row['version'], $max) > 0 ? $row['version'] : $max;
                }
            }
            foreach ($snapshot['events'] ?? [] as $event) {
                $event = $this->normalise($event);
                if ($event['origin'] !== $local && Federation::isReplicated($event['table'])) {
                    $this->store($event);
                }
            }
            foreach ($snapshot['cursors'] ?? [] as $origin => $seq) {
                if (is_string($origin) && $origin !== $local) {
                    DB::table('peer_cursors')->upsert([['origin' => $origin, 'last_seq' => (int) $seq, 'created_at' => now(),
                        'updated_at' => now()]], ['origin'], ['last_seq', 'updated_at']);
                }
            }
            if ($max !== null) {
                $this->hlc->receive($max);
            }
        });

        return $written;
    }

    /**
     * Re-pairing with a (new) hub (CONCEPT.md §10.6): its user events that were refused here while it was
     * not this instance's hub yet (admins created or changed between takeover and re-pairing) are applied
     * again, trusted like the membership state because an admin here confirmed the hub by its certificate
     * pin. They go through the normal apply path, so the uniqueness rules hold. Only the latest stored
     * event per user counts, and only when it is newer than the row here.
     *
     * @return int events applied
     */
    public function reapplyHubUsers(string $hub): int
    {
        $this->result = new ApplyResult($hub);
        $this->changes = [];
        $this->own = false;
        $this->trusted = true;
        $applied = 0;
        try {
            DB::transaction(function () use ($hub, &$applied) {
                $latest = [];
                foreach (DB::table(Outbox::TABLE)->where('origin', $hub)->where('table', 'users')->orderBy('seq')->get() as $event) {
                    $latest[$event->record_id] = $event;
                }
                foreach ($latest as $event) {
                    $event = $this->normalise($event);
                    $local = $this->row('users', $event['record_id']);
                    if ($local !== null && strcmp($event['version'], (string) $local['version']) <= 0) {
                        continue;
                    }
                    $changeCount = count($this->changes);
                    try {
                        $applied += DB::transaction(fn () => $this->applyEvent($event, false)) === 'applied' ? 1 : 0;
                    } catch (Throwable $e) {
                        // A broken event must not block the re-pairing; the row stays as it is here.
                        array_splice($this->changes, $changeCount);
                        Log::warning('Federation re-pairing: user event not applied', ['origin' => $hub,
                            'seq' => $event['seq'], 'id' => $event['record_id'], 'error' => mb_substr($e->getMessage(), 0, 150)]);
                    }
                }
                $changes = $this->changes;
                DB::afterCommit(function () use ($changes) {
                    foreach ($changes as $change) {
                        $this->hooks->dispatch($change);
                    }
                });
            });
        } finally {
            $this->trusted = false;
        }

        return $applied;
    }

    /** Pause an origin (e.g. invalid event signature) until an admin resumes it. */
    public function pauseOrigin(string $origin, string $reason): void
    {
        $this->own = false;
        DB::transaction(fn () => $this->pause($origin, $reason, new ApplyResult($origin)));
        Log::error('Federation apply paused', ['origin' => $origin, 'reason' => $reason]);
    }

    /**
     * Membership state from the hub at re-pairing (CONCEPT.md §10.2), trusted because an admin here
     * confirmed the hub by its certificate pin: instance rows, the hub setting and the security
     * settings, raw, last writer wins, without the authority rules (which dropped the new hub's
     * changes before).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function applyTrusted(string $table, array $rows): int
    {
        if (! in_array($table, ['instances', 'settings'], true)) {
            throw new RuntimeException("trusted apply not allowed for $table");
        }
        $columns = array_flip($this->columns($table));
        $written = 0;
        DB::transaction(function () use ($table, $rows, $columns, &$written) {
            $max = null;
            foreach ($rows as $row) {
                $row = array_intersect_key((array) $row, $columns);
                if (! isset($row['id'], $row['version']) || ! is_string($row['version'])) {
                    continue;
                }
                foreach ($row as $value) {
                    if (is_array($value) || is_object($value)) {
                        continue 2;
                    }
                }
                Hlc::parse($row['version']);
                if ($table === 'settings') {
                    $row['id'] = Setting::idFor((string) ($row['key'] ?? ''));
                }
                $current = $this->row($table, (string) $row['id']);
                // Same version but other content: the authority rules kept local fields of that event.
                $cmp = $current === null ? 1 : strcmp($row['version'], (string) $current['version']);
                if ($cmp < 0 || ($cmp === 0 && array_intersect_key($current, $row) == $row)) {
                    continue;
                }
                $this->put($table, $row, $current === null);
                $written++;
                $max = $max === null || strcmp($row['version'], $max) > 0 ? $row['version'] : $max;
            }
            if ($max !== null) {
                $this->hlc->receive($max);
            }
        });

        return $written;
    }

    public function unpause(string $origin): void
    {
        DB::table('peer_cursors')->where('origin', $origin)->update(['paused_at' => null, 'pause_reason' => null,
            'blocked_seq' => null, 'blocked_since' => null, 'blocked_attempts' => 0, 'updated_at' => now()]);
    }

    /**
     * A missing parent normally arrives with another origin's events. Waiting too long (attempts or
     * time, e.g. parent purged here) pauses the origin with the reason instead of retrying forever.
     */
    private function waitForParent(string $origin, ?object $cursor, array $event, ApplyResult $result): void
    {
        if ($this->own) {
            $result->stop('stopped', "missing parent row for {$event['table']}#{$event['record_id']} at seq {$event['seq']}");

            return;
        }
        $reason = "missing parent row for {$event['table']}#{$event['record_id']} at seq {$event['seq']}";
        $same = $cursor !== null && (int) $cursor->blocked_seq === $event['seq'];
        $attempts = $same ? (int) $cursor->blocked_attempts + 1 : 1;
        $since = $same && $cursor->blocked_since !== null ? Carbon::parse($cursor->blocked_since) : now();
        DB::table('peer_cursors')->upsert([['origin' => $origin, 'blocked_seq' => $event['seq'], 'blocked_since' => $since,
            'blocked_attempts' => $attempts, 'created_at' => now(), 'updated_at' => now()]], ['origin'],
            ['blocked_seq', 'blocked_since', 'blocked_attempts', 'updated_at']);

        if ($attempts >= (int) config('portal.federation.max_wait_attempts', 50)
            || $since->lte(now()->subMinutes((int) config('portal.federation.max_wait_minutes', 1440)))) {
            $this->pause($origin, "$reason (waited $attempts attempts)", $result);

            return;
        }
        $result->stop('stopped', $reason);
    }

    /** @return 'applied'|'ignored'|'dropped'|'waiting' */
    private function applyEvent(array $event, bool $snapshot): string
    {
        $table = $event['table'];
        $id = $event['record_id'];
        foreach ($event['payload'] as $column => $value) {
            if (is_array($value) || is_object($value)) {
                throw new RuntimeException("column $column is not a scalar");
            }
        }
        $incoming = array_diff_key(array_intersect_key($event['payload'], array_flip($this->columns($table))),
            array_flip(Federation::localColumns($table)));
        $incoming['version'] = $event['version'];
        if ($table === 'settings' && isset($incoming['key'])) {
            $id = Setting::idFor((string) $incoming['key']);
        }
        $incoming['id'] = $id;

        $local = $this->row($table, $id);
        if ($table === 'instances' || ($table === 'settings' && ($incoming['key'] ?? null) === Topology::HUB_SETTING)) {
            $incoming = $this->authorized($table, $event['origin'], $id, $incoming, $local);
            if ($incoming === null) {
                return 'dropped';
            }
        }
        $before = null;
        $incomingWins = true;
        if ($local === null) {
            if (! $snapshot && $this->beyondHorizon($event['version'])) {
                return 'dropped'; // purged here: no resurrection
            }
            $row = $incoming;
        } else {
            if ($event['version'] === $local['version']) {
                return 'ignored';
            }
            $incomingWins = strcmp($event['version'], (string) $local['version']) > 0;
            // Last writer wins on the rows' own states (conflict overlays are derived again below).
            $before = Constraints::has($table) ? (new Constraints)->baseOf($table, $local) : $local;
            $row = $incomingWins ? array_merge($before, $incoming) : $before;
            if ($table === 'grants') {
                $row = self::mergeGrant($local, $incoming, $incomingWins ? array_merge($local, $incoming) : $local);
            }
        }

        // Router rows end up in the FreeRADIUS clients file: id and address must be safe there (secrets are
        // encrypted here and checked when the file is rendered).
        if ($incomingWins && $table === 'nas_clients'
            && ($reason = ClientsFile::rowProblem((string) $row['id'], (string) ($row['nas_ip'] ?? ''))) !== null) {
            $this->refused($event, "router row refused: $reason");

            return 'dropped';
        }
        $guarded = $incomingWins && ! $this->own && ! $this->trusted && in_array($table, ['users', 'settings', 'sites'], true)
            && $this->nonHub($event['origin']);
        if ($guarded && ($reason = $this->securityViolation($table, $event['origin'], $row, $before ?? $local, $local)) !== null) {
            $this->refused($event, $reason);

            return 'dropped';
        }

        if (! $this->parentsExist($table, $row)) {
            // Parent purged here (old event) or a tombstone of an unknown row: nothing to wait for.
            if ($this->beyondHorizon($event['version']) || ($local === null && ($row['deleted_at'] ?? null) !== null)) {
                return 'dropped';
            }

            return 'waiting';
        }

        $written = $this->write($table, $row, $local, $before, $event['origin'], $guarded);
        if ($written === null) {
            $this->refused($event, 'e-mail or OIDC identity of an admin taken over by a conflict rule of a non-hub instance');

            return 'dropped';
        }

        return $written ? 'applied' : 'ignored';
    }

    /**
     * Authority rules for the federation's own membership (CONCEPT.md §10.6): every instance may write
     * any row, but the trust anchors of an instance (signing key, certificate pin, URLs) change only
     * by events of that instance itself; new instance rows (registrations) and tombstones/restores
     * (removals) only by the hub; the hub setting only by the current hub. Without a configured hub
     * (single instance, tests) an instance may only introduce its own row. Disallowed fields keep the
     * local value; a disallowed new row or hub change is dropped.
     *
     * @return array<string, mixed>|null incoming state to apply, null: drop the event
     */
    private function authorized(string $table, string $origin, string $id, array $incoming, ?array $local): ?array
    {
        $hub = Setting::get(Topology::HUB_SETTING);
        $hub = is_string($hub) && $hub !== '' ? $hub : null;

        if ($table === 'settings') {
            if ($hub === null || $origin === $hub) {
                return $incoming;
            }
            $this->rejected($table, $id, $origin, 'hub setting changed by a non-hub instance');

            return null;
        }

        if ($local === null) {
            if ($hub !== null ? $origin === $hub : $origin === $id) {
                return $incoming;
            }
            $this->rejected($table, $id, $origin, 'instance registered by a non-hub instance');

            return null;
        }
        if ($origin !== $id) {
            foreach (self::INSTANCE_TRUST_COLUMNS as $column) {
                if (array_key_exists($column, $incoming) && $incoming[$column] !== $local[$column]) {
                    $this->rejected($table, $id, $origin, "$column changed by another instance");
                    $incoming[$column] = $local[$column];
                }
            }
        }
        if ($origin !== $hub && array_key_exists('deleted_at', $incoming) && $incoming['deleted_at'] !== $local['deleted_at']) {
            $this->rejected($table, $id, $origin, 'instance removed or restored by a non-hub instance');
            $incoming['deleted_at'] = $local['deleted_at'];
        }

        return $incoming;
    }

    /**
     * A configured hub exists and $origin is not it. Without a configured hub (single instance, tests)
     * the security authority does not apply, like the hub setting rule.
     */
    private function nonHub(string $origin): bool
    {
        $hub = Setting::get(Topology::HUB_SETTING);

        return is_string($hub) && $hub !== '' && $origin !== $hub;
    }

    /**
     * Security authority (CONCEPT.md §10.6, decisions 2026-11-02 and 2026-11-03) for events of a non-hub
     * origin: only the hub creates, promotes, restores, demotes or deletes admins and changes the login
     * columns of an admin (Federation::ADMIN_LOGIN_COLUMNS) and the settings that control login or trust
     * (Federation::SECURITY_SETTINGS); hotspot host names and portal URL of a site change only by the hub
     * or the site's home instance.
     *
     * @param  array<string, mixed>  $row  resulting row state
     * @param  array<string, mixed>|null  $base  current own state of the row here (without conflict overlays)
     * @param  array<string, mixed>|null  $local  current row here
     * @return string|null reason to drop the event, null: allowed
     */
    private function securityViolation(string $table, string $origin, array $row, ?array $base, ?array $local): ?string
    {
        if ($table === 'users') {
            return $this->adminViolation($row, $base, $local);
        }
        if (! in_array($table, ['settings', 'sites'], true)) {
            return null;
        }

        if ($table === 'settings') {
            $key = (string) ($row['key'] ?? '');
            // The hub setting has its own rule (authorized(), takeover).
            if ($key === Topology::HUB_SETTING || ! in_array($key, Federation::SECURITY_SETTINGS, true)) {
                return null;
            }
            $same = $local !== null && ($local['value'] ?? null) === ($row['value'] ?? null)
                && ($local['deleted_at'] ?? null) === ($row['deleted_at'] ?? null);

            return $same ? null : "security setting $key changed by a non-hub instance";
        }

        $siteSettings = fn (?array $r) => array_intersect_key((array) json_decode((string) ($r['settings'] ?? ''), true),
            array_flip(Federation::SECURITY_SITE_SETTINGS));
        if ($siteSettings($row) == $siteSettings($local)) {
            return null;
        }
        // The home instance configures its own site (setup wizard, site page); a new site may name its creator.
        $home = $local !== null ? ($local['home_instance_id'] ?? null) : ($row['home_instance_id'] ?? null);

        return $home === $origin ? null : 'hotspot host names or portal URL of a site changed by an instance that is neither hub nor home instance';
    }

    /**
     * Users part of the security authority: no new, promoted or restored admin, and for an active admin
     * no change of a login column, no new recovery codes and no TOTP step in the future.
     *
     * @param  array<string, mixed>  $row  resulting row state
     * @param  array<string, mixed>|null  $base  own state here (compared, so a conflict rename here is no change)
     * @param  array<string, mixed>|null  $local  current row here
     */
    private function adminViolation(array $row, ?array $base, ?array $local): ?string
    {
        if (self::activeAdmin($row) && ! self::activeAdmin($local)) {
            return 'admin created, promoted or restored by a non-hub instance';
        }
        if (! self::activeAdmin($local)) {
            return null; // other users: unchanged rules
        }
        $base ??= $local;
        $changed = array_values(array_filter(Federation::ADMIN_LOGIN_COLUMNS, fn (string $column) => array_key_exists($column, $row)
            && ! self::sameValue($row[$column], $base[$column] ?? null)));
        if (! self::onlyCodesUsed($base['recovery_codes'] ?? null, $row['recovery_codes'] ?? null)) {
            $changed[] = 'recovery_codes';
        }
        $step = $row['totp_last_step'] ?? null;
        if ($step !== null && ! self::sameValue($step, $base['totp_last_step'] ?? null)
            && (int) $step > intdiv(max(time(), Carbon::now()->getTimestamp()), 30) + self::TOTP_STEP_MARGIN) {
            $changed[] = 'totp_last_step';
        }
        if ($changed === []) {
            return null;
        }
        if (in_array('deleted_at', $changed, true)) {
            return 'admin deleted by a non-hub instance';
        }
        if (in_array('role', $changed, true)) {
            return 'admin demoted by a non-hub instance';
        }

        return 'login data of an admin ('.implode(', ', $changed).') changed by a non-hub instance';
    }

    /** @param  array<string, mixed>|null  $row */
    private static function activeAdmin(?array $row): bool
    {
        return $row !== null && ($row['role'] ?? null) === Role::Admin->value && ($row['deleted_at'] ?? null) === null;
    }

    private static function sameValue(mixed $a, mixed $b): bool
    {
        return $a === null || $b === null ? $a === $b : (string) $a === (string) $b;
    }

    /** Recovery codes (JSON list of hashes): the new list may only lack codes of the old one. */
    private static function onlyCodesUsed(mixed $old, mixed $new): bool
    {
        if (self::sameValue($old, $new) || $new === null) {
            return true;
        }
        $oldCodes = $old === null ? [] : json_decode((string) $old, true);
        $newCodes = json_decode((string) $new, true);
        if (! is_array($oldCodes) || ! is_array($newCodes)) {
            return false;
        }

        return array_diff(array_map('strval', $newCodes), array_map('strval', $oldCodes)) === [];
    }

    /** A whole event refused by the security authority: logged and audited, the batch continues. */
    private function refused(array $event, string $reason): void
    {
        $this->rejected($event['table'], $event['record_id'], $event['origin'], $reason);
        if (Resync::required()) {
            return; // no replicated write while the restore resync runs (the log line above stays)
        }
        $id = DeterministicId::for("federation.refused:{$event['origin']}:{$event['seq']}");
        if (AuditLog::withTrashed()->whereKey($id)->exists()) {
            return;
        }
        (new AuditLog(['actor' => 'federation', 'action' => 'federation.event_refused',
            'subject_type' => Str::singular($event['table']), 'subject_id' => $event['record_id'],
            'data' => ['origin' => $event['origin'], 'seq' => $event['seq'], 'table' => $event['table'], 'reason' => $reason]]))
            ->forceFill(['id' => $id])->save();
    }

    private function rejected(string $table, string $id, string $origin, string $reason): void
    {
        $this->result->conflicts[] = "$table#$id: $reason (origin $origin)";
        Log::warning('Federation event field rejected', compact('table', 'id', 'origin', 'reason'));
    }

    /**
     * Write a row state; for tables with uniqueness rules also every other row of the affected groups,
     * in two phases (collision-free values first, then the final states) so values can move between rows.
     */
    /**
     * @param  bool  $guarded  event of a non-hub origin: a conflict rule may not rename the e-mail or OIDC
     *                         subject of another, active admin (it would lock the admin out or hand the login over)
     * @return bool|null written; null: refused by the guard, nothing written
     */
    private function write(string $table, array $row, ?array $local, ?array $before, string $origin, bool $guarded = false): ?bool
    {
        $id = $row['id'];
        if (! Constraints::has($table)) {
            if ($local !== null && $row == $local) {
                return false;
            }
            $this->put($table, $row, $local === null);
            $this->changes[] = new AppliedChange($table, $id, $local, $row, $origin);

            return true;
        }

        [$desired, $current] = (new Constraints)->resolve($table, $row, $before);
        $current[$id] = $local;
        $changed = array_filter($desired, fn (array $state, string $rowId) => $current[$rowId] === null
            || array_intersect_key($state, $current[$rowId]) != array_intersect_key($current[$rowId], $state), ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return false;
        }
        if ($guarded && $table === 'users') {
            foreach ($changed as $rowId => $state) {
                $previous = $current[$rowId];
                if ($rowId !== $id && self::activeAdmin($previous) && (! self::sameValue($state['email'] ?? null, $previous['email'] ?? null)
                    || ! self::sameValue($state['oidc_subject'] ?? null, $previous['oidc_subject'] ?? null))) {
                    return null;
                }
            }
        }

        self::$resolving = true;
        try {
            if (count($changed) > 1) {
                foreach ($changed as $rowId => $state) {
                    $this->put($table, Constraints::safe($table, $state), $current[$rowId] === null);
                    $current[$rowId] ??= $state;
                }
            }
            foreach ($changed as $rowId => $state) {
                $this->put($table, $state, $current[$rowId] === null);
            }
        } finally {
            self::$resolving = false;
        }

        foreach ($changed as $rowId => $state) {
            $previous = $rowId === $id ? $local : $current[$rowId];
            $this->changes[] = new AppliedChange($table, $rowId, $previous, $state, $rowId === $id ? $origin
                : (string) ($state['origin_instance_id'] ?? ''), $rowId === $id ? 'event' : 'conflict');
            if ($rowId !== $id) {
                $this->result->conflicts[] = "$table#$rowId changed by conflict rule";
            }
            $this->auditRenames($table, $rowId, $state, $previous);
        }

        return isset($changed[$id]);
    }

    private function put(string $table, array $row, bool $insert): void
    {
        if ($insert) {
            DB::table($table)->insert($row);
        } else {
            DB::table($table)->where('id', $row['id'])->update(array_diff_key($row, ['id' => true]));
        }
    }

    /** Audit entry when a row newly carries a renamed (suffixed) unique value. */
    private function auditRenames(string $table, string $id, array $state, ?array $previous): void
    {
        foreach (Constraints::RULES[$table] as $column => $rule) {
            $value = $state[$column] ?? null;
            if ($rule === 'older_id' && is_string($value) && str_contains($value, '-dup-'.strtolower(substr($id, -6)))
                && ($previous[$column] ?? null) !== $value) {
                $this->conflict($table, $id, $column);
            }
        }
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

    /** Audit entry with a deterministic id: every instance resolving the same conflict writes one row. */
    private function conflict(string $table, string $loserId, string $column): void
    {
        $this->result->conflicts[] = "$table#$loserId.$column renamed";
        Log::warning('Federation natural key conflict', compact('table', 'loserId', 'column'));
        $id = DeterministicId::for("federation.conflict:$table:$loserId:$column");
        if (AuditLog::withTrashed()->whereKey($id)->exists()) {
            return;
        }
        (new AuditLog(['actor' => 'federation', 'action' => 'federation.conflict', 'subject_type' => $table === 'users' ? 'user' : 'site',
            'subject_id' => $loserId, 'data' => ['column' => $column]]))
            ->forceFill(['id' => $id])->save();
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
            // The exact JSON string of the origin when available (signature stays verifiable for relays).
            'payload' => $event['raw'] ?? Outbox::encode($event['payload']),
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
        if ($this->own) {
            $result->stop('stopped', $reason);

            return;
        }
        DB::table('peer_cursors')->upsert([['origin' => $origin, 'paused_at' => now(), 'pause_reason' => mb_substr($reason, 0, 255),
            'created_at' => now(), 'updated_at' => now()]], ['origin'], ['paused_at', 'pause_reason', 'updated_at']);
        $result->stop('paused', $reason);
    }

    /** @return array{origin: string, seq: int, table: string, record_id: string, version: string, payload: array, raw: ?string, proto: int, sig: ?string} */
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
            'payload' => is_string($payload) ? (array) json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : (array) $payload,
            'raw' => is_string($payload) ? $payload : null,
            'proto' => (int) ($event['proto'] ?? Federation::FEDERATION_PROTOCOL),
            'sig' => $event['sig'] ?? null,
        ];
    }
}
