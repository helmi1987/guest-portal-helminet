<?php

namespace App\Guest;

use App\Models\Setting;
use Illuminate\Support\Arr;

/**
 * Guest texts editable in the admin UI (CONCEPT.md §9 "Guest texts", decision 2026-11-03): an allowlist
 * of lang keys of the guest pages, overridden per language in the replicated setting `guest_texts`
 * (key => locale => text, global for all sites). Empty means the lang file default. Overrides are applied
 * to the translator for guest-zone requests only (middleware ApplyGuestTexts) and for the admin preview.
 */
final class GuestTexts
{
    public const SETTING = 'guest_texts';

    /** Max. length of one text. */
    public const MAX = 500;

    /** Error page kinds (guest.error_pages.<kind>.title / .text). */
    public const ERROR_KINDS = ['no_hotspot', 'misconfigured', 'expired', 'revoked', 'locked', 'router_failed',
        'approval_denied', 'approval_expired', 'approval_busy', 'sms_busy', 'sms_failed', 'site_code_full',
        'no_login_method', 'method_disabled', 'social_failed', 'account_unavailable'];

    /** Editable keys (portal.<key>), grouped as in the admin form; errors: see ERROR_KINDS. */
    public const KEYS = [
        'general' => ['guest.title', 'guest.heading', 'guest.other_methods', 'guest.terms', 'guest.terms_show',
            'guest.terms_version', 'guest.terms_default'],
        'methods' => ['guest.intro', 'guest.intros.chooser', 'guest.choose.code', 'guest.intros.code', 'guest.choose.account',
            'guest.intros.account', 'guest.choose.sms',
            'guest.intros.sms', 'guest.choose.approval', 'guest.intros.approval', 'guest.choose.social', 'guest.intros.social'],
        'code' => ['guest.code', 'guest.connect', 'guest.first_name', 'guest.last_name'],
        'account' => ['guest.account_username', 'guest.account_password', 'guest.account_hint'],
        'sms' => ['guest.phone', 'guest.otp', 'guest.sms_request', 'guest.sms_title', 'guest.sms_sent', 'guest.sms_resend',
            'guest.sms_resent', 'guest.sms_change'],
        'approval' => ['guest.approval_request', 'guest.approval_waiting_title', 'guest.approval_waiting'],
        'social' => ['guest.social_with', 'guest.social_hint', 'guest.social_name_title', 'guest.social_name_intro'],
        'status' => ['guest.connecting', 'guest.waiting', 'guest.online', 'guest.remaining', 'guest.online_hint',
            'guest.continue', 'guest.noscript', 'guest.try_again'],
        'notices' => ['guest.limited_mode', 'guest.router_failed_notice', 'guest.access_ended.disconnected',
            'guest.access_ended.revoked', 'guest.access_ended.expired', 'guest.access_ended.sign_in_again'],
        'errors' => [
            'guest.error_pages.no_hotspot.title', 'guest.error_pages.no_hotspot.text',
            'guest.error_pages.misconfigured.title', 'guest.error_pages.misconfigured.text',
            'guest.error_pages.expired.title', 'guest.error_pages.expired.text',
            'guest.error_pages.revoked.title', 'guest.error_pages.revoked.text',
            'guest.error_pages.locked.title', 'guest.error_pages.locked.text',
            'guest.error_pages.router_failed.title', 'guest.error_pages.router_failed.text',
            'guest.error_pages.approval_denied.title', 'guest.error_pages.approval_denied.text',
            'guest.error_pages.approval_expired.title', 'guest.error_pages.approval_expired.text',
            'guest.error_pages.approval_busy.title', 'guest.error_pages.approval_busy.text',
            'guest.error_pages.sms_busy.title', 'guest.error_pages.sms_busy.text',
            'guest.error_pages.sms_failed.title', 'guest.error_pages.sms_failed.text',
            'guest.error_pages.site_code_full.title', 'guest.error_pages.site_code_full.text',
            'guest.error_pages.no_login_method.title', 'guest.error_pages.no_login_method.text',
            'guest.error_pages.method_disabled.title', 'guest.error_pages.method_disabled.text',
            'guest.error_pages.social_failed.title', 'guest.error_pages.social_failed.text',
            'guest.error_pages.account_unavailable.title', 'guest.error_pages.account_unavailable.text',
        ],
    ];

    /** @var array<string, array<string, mixed>> lang file lines per locale (defaults, without overrides) */
    private static array $files = [];

    /** @var array<string, list<string>> keys overridden in the translator per locale (see restore()) */
    private static array $applied = [];

    /** @return list<string> all editable keys */
    public static function keys(): array
    {
        return array_merge(...array_values(self::KEYS));
    }

    public static function allowed(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** Form field name of a key (dots would be nested input): "guest.intros.code" -> "guest-intros-code". */
    public static function field(string $key): string
    {
        return str_replace('.', '-', $key);
    }

    /** Text of the lang file (never an override). */
    public static function default(string $key, string $locale): string
    {
        self::$files[$locale] ??= app('translator')->getLoader()->load($locale, 'portal', '*');
        $line = Arr::get(self::$files[$locale], $key);

        return is_string($line) ? $line : '';
    }

    /** @return list<string> placeholder names (without colon) of the default text, e.g. ["phone", "minutes"] */
    public static function placeholders(string $key, string $locale): array
    {
        preg_match_all('/:([a-z][a-z0-9_]*)/i', self::default($key, $locale), $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> placeholders of the default text that the given text lacks */
    public static function missingPlaceholders(string $key, string $locale, string $text): array
    {
        return array_values(array_filter(self::placeholders($key, $locale),
            fn (string $name) => ! preg_match('/:'.preg_quote($name, '/').'/i', $text)));
    }

    /** One line of plain text: trimmed, line breaks become spaces. */
    public static function normalize(mixed $text): string
    {
        return is_string($text) ? trim((string) preg_replace('/\s*\R\s*/u', ' ', $text)) : '';
    }

    /** @return array<string, array<string, string>> stored overrides: key => locale => text (allowlisted keys only) */
    public static function all(): array
    {
        $stored = Setting::get(self::SETTING, []);
        $out = [];
        foreach (self::keys() as $key) {
            foreach ((array) (is_array($stored) ? ($stored[$key] ?? []) : []) as $locale => $text) {
                if (is_string($locale) && is_string($text) && $text !== '') {
                    $out[$key][$locale] = $text;
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> stored overrides of one locale: key => text */
    public static function overrides(string $locale): array
    {
        return array_filter(array_map(fn (array $texts) => $texts[$locale] ?? null, self::all()), fn ($t) => $t !== null);
    }

    /**
     * Store the overrides of one locale (key => text; empty or default text = no override).
     *
     * @return list<string> keys whose override changed
     */
    public static function save(string $locale, array $texts): array
    {
        $all = self::all();
        $changed = [];
        foreach (self::keys() as $key) {
            $text = self::normalize($texts[$key] ?? '');
            $text = $text === self::default($key, $locale) ? '' : $text;
            if ($text !== ($all[$key][$locale] ?? '')) {
                $changed[] = $key;
            }
            if ($text === '') {
                unset($all[$key][$locale]);
            } else {
                $all[$key][$locale] = $text;
            }
            if (($all[$key] ?? null) === []) {
                unset($all[$key]);
            }
        }
        if ($changed !== []) {
            Setting::put(self::SETTING, $all);
        }

        return $changed;
    }

    /**
     * Put the overrides of a locale into the translator for this request (null: the stored ones). Callers
     * undo it with restore() once the response is rendered, so later requests of a long-lived process
     * (tests, queue) and other zones see the defaults.
     *
     * @param  array<string, string>|null  $texts  key => text
     */
    public static function apply(string $locale, ?array $texts = null): void
    {
        $texts = array_filter($texts ?? self::overrides($locale), fn ($text, $key) => is_string($text) && $text !== ''
            && self::allowed((string) $key), ARRAY_FILTER_USE_BOTH);
        if ($texts === []) {
            return;
        }
        $translator = app('translator');
        // addLines() alone would mark the group as loaded and hide the rest of the lang file.
        $translator->load('*', 'portal', $locale);
        $translator->addLines(collect($texts)->mapWithKeys(fn (string $text, string $key) => ['portal.'.$key => $text])->all(), $locale);
        self::$applied[$locale] = array_values(array_unique([...self::$applied[$locale] ?? [], ...array_keys($texts)]));
    }

    /** Put the lang file texts back for every key apply() replaced. */
    public static function restore(): void
    {
        foreach (self::$applied as $locale => $keys) {
            app('translator')->addLines(collect($keys)->mapWithKeys(fn (string $key) => ['portal.'.$key => self::default($key, $locale)])->all(), $locale);
        }
        self::$applied = [];
    }
}
