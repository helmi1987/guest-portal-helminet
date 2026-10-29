<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Display time zone (CONCEPT.md §2.2): the database, expiry logic and the scheduler stay in UTC
 * (app.timezone); only what people read or enter is converted. Zone from APP_TIMEZONE, else TZ,
 * else UTC; an unknown zone name falls back to UTC.
 */
final class LocalTime
{
    public static function zone(): string
    {
        $zone = (string) config('portal.display_timezone');

        try {
            return (new DateTimeZone($zone))->getName();
        } catch (Throwable) {
            return 'UTC';
        }
    }

    /** Formats a stored (UTC) time in the display zone; null stays null (for "?? '–'"). */
    public static function format(?CarbonInterface $time, string $format = 'Y-m-d H:i'): ?string
    {
        return $time?->copy()->setTimezone(self::zone())->format($format);
    }

    /** Current time in the display zone. */
    public static function now(): Carbon
    {
        return Carbon::now(self::zone());
    }

    /** Parses admin input (e.g. datetime-local) given in the display zone; returns UTC. */
    public static function parse(?string $value): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value, self::zone())->utc();
    }

    /** Start of the given day (date input) in the display zone, as UTC. */
    public static function startOfDay(string $date): Carbon
    {
        return Carbon::parse($date, self::zone())->startOfDay()->utc();
    }

    /** End of the given day (date input) in the display zone, as UTC. */
    public static function endOfDay(string $date): Carbon
    {
        return Carbon::parse($date, self::zone())->endOfDay()->utc();
    }
}
