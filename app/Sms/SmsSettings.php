<?php

namespace App\Sms;

use App\Models\Setting;

/**
 * Replicated SMS settings (CONCEPT.md §7.2 B, §9) with defaults from config/portal.php.
 */
class SmsSettings
{
    /** Grant duration in seconds; time runs from the successful OTP verification. */
    public static function durationS(): int
    {
        return max(60, (int) Setting::get('sms_duration_s', config('portal.sms.duration_s')));
    }

    /** @return list<string> ISO 3166 alpha-2 region codes guests may use */
    public static function countries(): array
    {
        $countries = Setting::get('sms_countries');

        return is_array($countries) && $countries !== [] ? array_values($countries) : config('portal.sms.countries');
    }

    /** @return array{phone_hour:int, phone_day:int, mac_hour:int, mac_day:int, site_hour:int, site_day:int, global_day:int} */
    public static function caps(): array
    {
        $stored = Setting::get('sms_caps');
        $caps = config('portal.sms.caps');
        foreach (array_keys($caps) as $key) {
            if (isset($stored[$key])) {
                $caps[$key] = (int) $stored[$key];
            }
        }

        return $caps;
    }

    public const TEXT_MAX = 300;

    /**
     * SMS text template per locale: the admin's own text, else the language default. Placeholders
     * `:code`, `:minutes`, `:host`.
     */
    public static function text(string $locale): string
    {
        $own = Setting::get('sms_text');
        if (is_array($own) && is_string($own[$locale] ?? null) && trim($own[$locale]) !== '') {
            return $own[$locale];
        }

        return self::defaultText($locale);
    }

    public static function defaultText(string $locale): string
    {
        return (string) trans('portal.sms.text', [], $locale);
    }
}
