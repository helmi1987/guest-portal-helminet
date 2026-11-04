<?php

namespace App\Sms;

use App\Models\Channel;
use App\Sms\Drivers\SmsDrivers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One active SMS provider with ordered backups (CONCEPT.md §7.2 B, decision v0.26.0).
 *
 * Role and position live in the channel's replicated, encrypted `config` under reserved keys (no schema
 * change, older instances ignore them): `_sms_role` (primary | backup) and `_sms_order` (int). `enabled`
 * stays the master switch (off = disabled). The list order is: the stored primary, then by stored order;
 * channels without stored keys follow by id (creation order). Without any stored keys (data from before
 * 0.26.0) the first enabled channel by name is the primary, as the old "first enabled by name" rule sent,
 * the others follow by id. The keys are written only when an admin changes the list (normalise()).
 *
 * The failure state (cooldown, outage) is instance-local in the cache, never replicated.
 */
final class SmsProviders
{
    public const ROLE = '_sms_role';

    public const ORDER = '_sms_order';

    public const RESERVED = [self::ROLE, self::ORDER];

    public const PRIMARY = 'primary';

    public const BACKUP = 'backup';

    /** All SMS channels in list order (position 0 = primary when enabled). @return Collection<int, Channel> */
    public static function ordered(): Collection
    {
        $channels = Channel::query()->where('kind', Channel::KIND_SMS)->orderBy('id')->get();
        $stored = $channels->filter(fn (Channel $c) => isset($c->config[self::ROLE]));
        if ($stored->isEmpty()) {
            $primary = $channels->where('enabled', true)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->first();

            return $primary === null ? $channels->values()
                : collect([$primary])->concat($channels->reject(fn (Channel $c) => $c->is($primary)))->values();
        }

        return $channels->sortBy([
            fn (Channel $a, Channel $b) => self::rank($a) <=> self::rank($b),
            fn (Channel $a, Channel $b) => self::order($a) <=> self::order($b),
            fn (Channel $a, Channel $b) => strcmp($a->id, $b->id),
        ])->values();
    }

    /** Enabled channels in send order: the active provider first, then the backups. @return Collection<int, Channel> */
    public static function chain(): Collection
    {
        return self::ordered()->filter(fn (Channel $c) => self::sends($c))->values();
    }

    /** Whether the channel takes part in sending guest codes (enabled, not a test-only driver). */
    public static function sends(Channel $channel): bool
    {
        return $channel->enabled && ! in_array($channel->driver, SmsDrivers::TEST_ONLY, true);
    }

    /**
     * Badge per channel id: ['role' => 'primary'|'backup'|'off', 'n' => backup number].
     *
     * @return array<string, array{role: string, n: int}>
     */
    public static function badges(?Collection $ordered = null): array
    {
        $badges = [];
        $n = 0;
        foreach ($ordered ?? self::ordered() as $channel) {
            $badges[$channel->id] = ! self::sends($channel) ? ['role' => 'off', 'n' => 0]
                : ($n++ === 0 ? ['role' => self::PRIMARY, 'n' => 0] : ['role' => self::BACKUP, 'n' => $n - 1]);
        }

        return $badges;
    }

    /** Make $channel the active provider (enables it); the previous one becomes the first backup. */
    public static function makePrimary(Channel $channel): void
    {
        DB::transaction(function () use ($channel) {
            $list = self::ordered()->reject(fn (Channel $c) => $c->is($channel))->values();
            $channel->enabled = true;
            self::normalise(collect([$channel])->concat($list));
        });
    }

    /**
     * Move a channel one place up (-1) or down (+1) among the backups; the active provider keeps position
     * 0 (use makePrimary()). Returns false when the move is not possible.
     */
    public static function move(Channel $channel, int $direction): bool
    {
        return DB::transaction(function () use ($channel, $direction) {
            $list = self::ordered()->values()->all();
            $index = collect($list)->search(fn (Channel $c) => $c->is($channel));
            $target = $index === false ? -1 : $index + $direction;
            $primaryFixed = $list !== [] && self::sends($list[0]);
            if ($index === false || $target < 0 || $target >= count($list) || ($primaryFixed && ($index === 0 || $target === 0))) {
                return false;
            }
            [$list[$index], $list[$target]] = [$list[$target], $list[$index]];
            self::normalise(collect($list));

            return true;
        });
    }

    /** Store role and order for every SMS channel in the given order (only changed rows are written). */
    public static function normalise(?Collection $list = null): void
    {
        $primarySet = false;
        foreach (($list ?? self::ordered())->values() as $i => $channel) {
            $role = ! $primarySet && self::sends($channel) ? self::PRIMARY : self::BACKUP;
            $primarySet = $primarySet || $role === self::PRIMARY;
            $config = $channel->config ?? [];
            if (($config[self::ROLE] ?? null) !== $role || ($config[self::ORDER] ?? null) !== $i) {
                $channel->config = [...$config, self::ROLE => $role, self::ORDER => $i];
            }
            if ($channel->isDirty()) {
                $channel->save();
            }
        }
    }

    /** Reserved keys of a stored config, to keep them when the admin form rebuilds the config. */
    public static function reservedOf(?array $config): array
    {
        return array_intersect_key($config ?? [], array_flip(self::RESERVED));
    }

    private static function rank(Channel $channel): int
    {
        return match ($channel->config[self::ROLE] ?? null) {
            self::PRIMARY => 0,
            self::BACKUP => 1,
            default => 2,
        };
    }

    private static function order(Channel $channel): int
    {
        return (int) ($channel->config[self::ORDER] ?? PHP_INT_MAX);
    }

    // Instance-local failure state (CONCEPT.md §7.2 B): cooldown per channel and the current outage.

    private const DOWN = 'sms-provider:down:';

    public const OUTAGE = 'sms-provider:outage';

    /** Mark a channel failed for the cooldown; returns the failure time. */
    public static function markDown(Channel $channel): void
    {
        Cache::put(self::DOWN.$channel->id, now()->getTimestamp(), SmsSettings::failoverCooldownS());
    }

    public static function clearDown(Channel $channel): void
    {
        Cache::forget(self::DOWN.$channel->id);
    }

    /** Failure time (Unix) of a channel in cooldown, else null. */
    public static function downSince(Channel $channel): ?int
    {
        $at = Cache::get(self::DOWN.$channel->id);

        return is_int($at) ? $at : null;
    }

    /** @return array{primary: string, used: string, failed_at: int}|null */
    public static function outage(): ?array
    {
        $outage = Cache::get(self::OUTAGE);

        return is_array($outage) ? $outage : null;
    }
}
