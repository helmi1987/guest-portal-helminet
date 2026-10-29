<?php

namespace App\Branding;

use App\Models\Setting;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Branding settings (CONCEPT.md §9, docs/branding.md): global defaults in the replicated setting
 * "branding", optional per-site overrides in the replicated site setting "branding". A key present
 * in the site overrides means "override", a missing key means "inherit". Values:
 * logo/favicon {hash, ext} | null, accent/background/text "#rrggbb" | null, css string.
 */
final class BrandingSettings
{
    public const SETTING = 'branding';

    public const COLORS = ['accent', 'background', 'text'];

    public const FIELDS = ['logo', 'favicon', 'accent', 'background', 'text', 'css'];

    /** Request attribute a controller sets when the guest page's site is not in the session. */
    public const SITE_ATTRIBUTE = 'branding_site';

    /** @return array{logo: ?array, favicon: ?array, accent: ?string, background: ?string, text: ?string, css: string} */
    public static function global(): array
    {
        return self::normalize((array) Setting::get(self::SETTING, []), true);
    }

    public static function saveGlobal(array $values): void
    {
        Setting::put(self::SETTING, self::normalize($values, true));
    }

    /** Only the overridden fields of a site. */
    public static function siteOverrides(Site $site): array
    {
        return self::normalize((array) $site->setting(self::SETTING, []), false);
    }

    public static function saveSite(Site $site, array $overrides): void
    {
        $settings = (array) $site->settings;
        $overrides = self::normalize($overrides, false);
        if ($overrides === []) {
            unset($settings[self::SETTING]);
        } else {
            $settings[self::SETTING] = $overrides;
        }
        $site->settings = $settings;
        $site->save();
    }

    /** Effective branding: site override -> global setting -> built-in default (null values). */
    public static function resolve(?Site $site): Branding
    {
        $global = self::global();
        $overrides = $site ? self::siteOverrides($site) : [];

        return new Branding(array_replace($global, $overrides), $site,
            $site !== null && array_intersect_key($overrides, array_flip([...self::COLORS, 'css'])) !== []);
    }

    /** Branding of a guest page: the site of the current portal session (or set by the controller), else global. */
    public static function forGuest(Request $request): Branding
    {
        if (! $request->attributes->has(self::SITE_ATTRIBUTE)) {
            $id = $request->hasSession() ? data_get($request->session()->get('portal'), 'site') : null;
            $request->attributes->set(self::SITE_ATTRIBUTE, is_string($id) ? Site::find($id) : null);
        }

        return self::resolve($request->attributes->get(self::SITE_ATTRIBUTE));
    }

    /** The admin UI always uses the global branding. */
    public static function forAdmin(): Branding
    {
        return self::resolve(null);
    }

    /**
     * File names referenced by the global setting or any site (incl. tombstoned sites, which could
     * be restored by replication).
     *
     * @return list<string>
     */
    public static function referencedFiles(): array
    {
        $values = [self::global()];
        foreach (Site::withTrashed()->get() as $site) {
            $values[] = self::siteOverrides($site);
        }
        $files = [];
        foreach ($values as $value) {
            foreach (['logo', 'favicon'] as $key) {
                if (BrandingAssets::valid($value[$key] ?? null)) {
                    $files[] = BrandingAssets::fileName($value[$key]);
                }
            }
        }

        return array_values(array_unique($files));
    }

    /** "#abc" / "#AABBCC" -> "#aabbcc"; null for empty or invalid input. */
    public static function color(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value))) {
            return null;
        }
        $hex = strtolower(ltrim(trim($value), '#'));
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    /**
     * Drops unknown keys and malformed values. $complete: all fields (global), otherwise only the
     * keys present (site overrides).
     */
    private static function normalize(array $values, bool $complete): array
    {
        $out = [];
        foreach (self::FIELDS as $key) {
            if (! $complete && ! array_key_exists($key, $values)) {
                continue;
            }
            $value = $values[$key] ?? null;
            $out[$key] = match ($key) {
                'logo', 'favicon' => BrandingAssets::valid($value) ? ['hash' => $value['hash'], 'ext' => $value['ext']] : null,
                'css' => is_string($value) ? $value : '',
                default => self::color($value),
            };
            // A site override without a value (no file, invalid colour) means inherit.
            if (! $complete && $key !== 'css' && $out[$key] === null) {
                unset($out[$key]);
            }
        }

        return $out;
    }
}
