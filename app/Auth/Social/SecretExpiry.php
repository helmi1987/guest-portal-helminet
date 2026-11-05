<?php

namespace App\Auth\Social;

use App\Support\LocalTime;
use Illuminate\Support\Carbon;

/**
 * Expiry date of the social login provider secrets (CONCEPT.md §9, decision 2026-11-05, v0.31.0): an optional
 * date per provider except Apple (SocialSettings::EXPIRING), entered by the admin. Days are counted in the display
 * time zone: 0 = expires today, negative = expired. The date only informs: an expired date neither disables the
 * provider nor makes it "not ready" (the date may be wrong; the check button tells the truth).
 */
final class SecretExpiry
{
    /** From this many days left the status is a warning and the banner lists the provider. */
    public const WARN_DAYS = 30;

    public const OK = 'ok';

    public const SOON = 'soon';

    public const EXPIRED = 'expired';

    /** Signed whole days from today (display zone) to the date. */
    public static function daysLeft(string $date): int
    {
        $today = LocalTime::now()->startOfDay();
        $day = Carbon::createFromFormat('!Y-m-d', $date, LocalTime::zone());

        return (int) round($today->diffInDays($day, false));
    }

    /**
     * Status of one provider's secret date; null without a date.
     *
     * @return array{date: string, days: int, level: string}|null
     */
    public static function status(string $provider): ?array
    {
        if (! in_array($provider, SocialSettings::EXPIRING, true) || ($date = SocialSettings::secretExpiresAt($provider)) === null) {
            return null;
        }
        $days = self::daysLeft($date);

        return ['date' => $date, 'days' => $days, 'level' => match (true) {
            $days < 0 => self::EXPIRED,
            $days <= self::WARN_DAYS => self::SOON,
            default => self::OK,
        }];
    }

    /**
     * Enabled providers whose secret expires within WARN_DAYS or has expired, in display order (banner, reminders
     * use the same rule with their own stages).
     *
     * @return array<string, array{date: string, days: int, level: string}>
     */
    public static function attention(): array
    {
        $out = [];
        foreach (SocialSettings::order() as $provider) {
            $status = self::status($provider);
            if ($status !== null && $status['level'] !== self::OK && SocialSettings::provider($provider)['enabled']) {
                $out[$provider] = $status;
            }
        }

        return $out;
    }

    /** Date as shown to people (d.m.Y). */
    public static function format(string $date): string
    {
        return Carbon::createFromFormat('!Y-m-d', $date)->format('d.m.Y');
    }
}
