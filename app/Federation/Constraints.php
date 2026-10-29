<?php

namespace App\Federation;

use Illuminate\Support\Facades\DB;

/**
 * Uniqueness rules of replicated tables (CONCEPT.md §10.3), resolved as a pure function of the
 * rows' own states, so every instance reaches the same result in any event order.
 *
 * A row's own ("base") state is the payload of the event that carries its current version; a conflict
 * rule only overlays it (rename, unbind, tombstone, deactivate) without a new version. After every
 * applied event the groups the row left and joined are evaluated again from the base states of all
 * their members.
 */
final class Constraints
{
    /**
     * table => [group column => rule]
     * older_id: unique value, the smallest ULID keeps it, the others get a suffix.
     * newer_version: one active row per value, the highest version among the active rows wins.
     * newest_binding: devices, one active per grant; the newest binding (bound_at, then id) among the
     * active devices wins, the others are unbound at its binding time.
     */
    public const RULES = [
        'sites' => ['slug' => 'older_id'],
        'users' => ['email' => 'older_id', 'oidc_subject' => 'older_id'],
        'mac_whitelist' => ['mac' => 'newer_version'],
        'site_codes' => ['site_id' => 'newer_version'],
        'devices' => ['grant_id' => 'newest_binding'],
    ];

    /** Column lengths for the rename suffix. */
    private const MAX_LENGTH = ['slug' => 64, 'email' => 255, 'oidc_subject' => 512];

    /** @var array<string, array<string, mixed>> id => base state */
    private array $bases = [];

    /** @var array<string, array<string, mixed>> id => current row */
    private array $current = [];

    public static function has(string $table): bool
    {
        return isset(self::RULES[$table]);
    }

    /**
     * Desired states of all rows affected by writing $row (base state) whose previous base was $before.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>} desired and current rows by id
     */
    public function resolve(string $table, array $row, ?array $before): array
    {
        $this->bases = [$row['id'] => $row];
        $this->current = [];
        $ids = [$row['id'] => true];
        foreach ([$before, $row] as $state) {
            foreach ($state === null ? [] : $this->groupsOf($table, $state) as [$column, $value]) {
                foreach ($this->members($table, $column, $value) as $id) {
                    $ids[$id] = true;
                }
            }
        }

        $evaluated = [];
        $desired = [];
        foreach (array_keys($ids) as $id) {
            $state = $this->bases[$id];
            foreach ($this->groupsOf($table, $state) as [$column, $value]) {
                $key = $column."\0".$value;
                $evaluated[$key] ??= $this->evaluate($table, $column, $value);
                $state = array_merge($state, $evaluated[$key][$id] ?? []);
            }
            $desired[$id] = $state;
        }

        return [$desired, $this->current];
    }

    /**
     * State during the first write phase: constrained values that cannot collide with any other row,
     * so rows can swap values within one batch.
     */
    public static function safe(string $table, array $row): array
    {
        foreach (self::RULES[$table] ?? [] as $column => $rule) {
            if ($rule === 'older_id' && ($row[$column] ?? null) !== null) {
                $row[$column] = self::suffixed($table, $column, $row[$column], $row['id']);
            }
        }

        return match ($table) {
            'mac_whitelist' => array_merge($row, ['deleted_at' => $row['deleted_at'] ?? '1970-01-01 00:00:00']),
            'devices' => array_merge($row, ['unbound_at' => $row['unbound_at'] ?? '1970-01-01 00:00:00']),
            default => $row,
        };
    }

    public static function suffixed(string $table, string $column, string $value, string $id): string
    {
        $suffix = '-dup-'.strtolower(substr($id, -6));
        if ($column === 'email' && ($at = strrpos($value, '@')) !== false) {
            return mb_substr(substr($value, 0, $at), 0, 200 - strlen($suffix)).$suffix.substr($value, $at);
        }

        return mb_substr($value, 0, (self::MAX_LENGTH[$column] ?? 255) - strlen($suffix)).$suffix;
    }

    /** LIKE pattern (escape character backslash) matching every suffixed form of a value. */
    private static function suffixPattern(string $column, string $value): string
    {
        $escape = fn (string $v) => addcslashes($v, '\\%_');
        if ($column === 'email' && ($at = strrpos($value, '@')) !== false) {
            return $escape(mb_substr(substr($value, 0, $at), 0, 200 - 11)).'-dup-______'.$escape(substr($value, $at));
        }

        return $escape(mb_substr($value, 0, (self::MAX_LENGTH[$column] ?? 255) - 11)).'-dup-______';
    }

    /** @return list<array{0: string, 1: string}> (column, value) groups a base state belongs to */
    private function groupsOf(string $table, array $state): array
    {
        $groups = [];
        foreach (self::RULES[$table] as $column => $rule) {
            if (($state[$column] ?? null) !== null) {
                $groups[] = [$column, (string) $state[$column]];
            }
        }

        return $groups;
    }

    /** @return array<string, array<string, mixed>> id => changes over the base state */
    private function evaluate(string $table, string $column, string $value): array
    {
        $members = [];
        foreach ($this->members($table, $column, $value) as $id) {
            $members[$id] = $this->bases[$id];
        }

        return match (self::RULES[$table][$column]) {
            'older_id' => $this->olderId($table, $column, $value, $members),
            'newer_version' => $this->newerVersion($table, $members),
            'newest_binding' => $this->newestBinding($members),
        };
    }

    private function olderId(string $table, string $column, string $value, array $members): array
    {
        if (count($members) < 2) {
            return [];
        }
        $winner = min(array_keys($members));
        $changes = [];
        foreach (array_keys($members) as $id) {
            if ($id !== $winner) {
                $changes[$id] = [$column => self::suffixed($table, $column, $value, $id)];
            }
        }

        return $changes;
    }

    private function newerVersion(string $table, array $members): array
    {
        $active = array_filter($members, fn (array $r) => ($r['deleted_at'] ?? null) === null
            && ($table !== 'site_codes' || ((bool) ($r['active'] ?? true) && ($r['deactivated_at'] ?? null) === null)));
        if (count($active) < 2) {
            return [];
        }
        uasort($active, fn ($a, $b) => strcmp((string) $b['version'], (string) $a['version']));
        $winner = reset($active);
        $changes = [];
        foreach (array_slice(array_keys($active), 1) as $id) {
            $changes[$id] = $table === 'site_codes'
                ? ['active' => 0, 'deactivated_at' => $winner['created_at']]
                : ['deleted_at' => $winner['created_at'] ?? $winner['updated_at']];
        }

        return $changes;
    }

    private function newestBinding(array $members): array
    {
        $active = array_filter($members, fn (array $r) => ($r['deleted_at'] ?? null) === null && ($r['unbound_at'] ?? null) === null);
        if (count($active) < 2) {
            return [];
        }
        uasort($active, fn ($a, $b) => [$b['bound_at'], $b['id']] <=> [$a['bound_at'], $a['id']]);
        $winner = reset($active);
        $changes = [];
        foreach (array_slice(array_keys($active), 1) as $id) {
            $changes[$id] = ['unbound_at' => $winner['bound_at']];
        }

        return $changes;
    }

    /** @return list<string> ids of all rows whose base state has $value in $column */
    private function members(string $table, string $column, string $value): array
    {
        $query = DB::table($table)->where($column, $value);
        if (self::RULES[$table][$column] === 'older_id') {
            // Renamed losers carry the value with a suffix.
            $pattern = self::suffixPattern($column, $value);
            $query->orWhereRaw('"'.$column.'" LIKE ? ESCAPE \'\\\'', [$pattern]);
        }
        $ids = [];
        foreach ($query->get() as $current) {
            $current = (array) $current;
            $this->current[$current['id']] = $current;
            $this->bases[$current['id']] ??= $this->base($table, $current);
        }
        foreach ($this->bases as $id => $base) {
            if (($base[$column] ?? null) !== null && (string) $base[$column] === $value) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Own state of a row: its current row overlaid with the payload of the event of its version. */
    private function base(string $table, array $current): array
    {
        $payload = DB::table(Outbox::TABLE)->where('table', $table)->where('record_id', $current['id'])
            ->where('version', $current['version'])->value('payload');
        if ($payload === null) {
            return $current;
        }

        return array_merge($current, array_intersect_key((array) json_decode($payload, true), $current),
            ['id' => $current['id'], 'version' => $current['version']]);
    }

    /** Base state of a row with the given current state (for the row being replaced). */
    public function baseOf(string $table, array $current): array
    {
        return $this->base($table, $current);
    }
}
