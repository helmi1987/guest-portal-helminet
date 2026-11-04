<?php

namespace App\Nas\Unifi;

use App\Federation\DataKey;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Controller settings of a UniFi site (CONCEPT.md §5.1, decision 2026-11-04, v0.27.0): the replicated site
 * setting `controller` (a security site setting, Federation::SECURITY_SITE_SETTINGS, like the directory:
 * it decides where the API key goes). The API key and the password of the local account are encrypted with
 * the federation data key (CONCEPT.md §10.2) and never leave this class except for the controller calls.
 */
final class UnifiSettings
{
    public const SITE_SETTING = 'controller';

    /** Official Network API with an API key (UniFi OS consoles, UniFi OS Server). */
    public const MODE_API_KEY = 'api_key';

    /** Undocumented cookie login with a local account (standalone Network Application, also UniFi OS). */
    public const MODE_LEGACY = 'legacy';

    public const MODES = [self::MODE_API_KEY, self::MODE_LEGACY];

    /** Encrypted fields of the setting. */
    public const SECRETS = ['api_key', 'password'];

    /** Default authorization when a grant has no end yet (should not happen after a login). */
    public const DEFAULT_DURATION_S = 86400;

    /** Public fields of the site setting (the secrets are stored next to them, encrypted). */
    public const SITE_DEFAULTS = [
        'url' => '',
        'mode' => self::MODE_API_KEY,
        'username' => '',
        'unifi_site' => 'default',
        'verify_tls' => true,
        'tls_pin' => '',
        'duration_default_s' => self::DEFAULT_DURATION_S,
    ];

    /**
     * Public fields (defaults merged, types normalised, no secrets).
     *
     * @return array{url: string, mode: string, username: string, unifi_site: string, verify_tls: bool, tls_pin: string, duration_default_s: int}
     */
    public static function forSite(Site $site): array
    {
        $stored = $site->setting(self::SITE_SETTING, []);
        $stored = is_array($stored) ? array_intersect_key($stored, self::SITE_DEFAULTS) : [];
        $c = array_merge(self::SITE_DEFAULTS, $stored);
        $unifiSite = trim((string) $c['unifi_site']);

        return [
            'url' => rtrim(trim((string) $c['url']), '/'),
            'mode' => in_array($c['mode'], self::MODES, true) ? $c['mode'] : self::MODE_API_KEY,
            'username' => (string) $c['username'],
            'unifi_site' => $unifiSite !== '' ? $unifiSite : 'default',
            'verify_tls' => (bool) $c['verify_tls'],
            'tls_pin' => (string) $c['tls_pin'],
            'duration_default_s' => max(60, min(30 * 86400, (int) $c['duration_default_s'])),
        ];
    }

    /** URL, and the credential of the selected mode, readable. */
    public static function isConfigured(Site $site): bool
    {
        $c = self::forSite($site);
        if ($c['url'] === '') {
            return false;
        }

        return $c['mode'] === self::MODE_API_KEY
            ? self::secret($site, 'api_key') !== null
            : $c['username'] !== '' && self::secret($site, 'password') !== null;
    }

    /** Connection settings with the decrypted secrets (empty strings when not set). */
    public static function config(Site $site): UnifiConfig
    {
        $c = self::forSite($site);

        return new UnifiConfig($c['url'], $c['mode'], self::secret($site, 'api_key') ?? '', $c['username'],
            self::secret($site, 'password') ?? '', $c['unifi_site'], $c['verify_tls'], $c['tls_pin']);
    }

    /** Portal sites whose controller setting names this UniFi site (redirect /guest/s/{unifiSite}). */
    public static function sitesFor(string $unifiSite): Collection
    {
        return Site::query()->where('driver', 'unifi')->get()
            ->filter(fn (Site $site) => self::forSite($site)['unifi_site'] === $unifiSite)->values();
    }

    public static function hasSecret(Site $site, string $field): bool
    {
        return self::stored($site, $field) !== null;
    }

    /** A secret is stored but cannot be decrypted (e.g. unknown data key): it must be entered again. */
    public static function secretBroken(Site $site, string $field): bool
    {
        $stored = self::stored($site, $field);

        return $stored !== null && self::decrypt($stored) === null;
    }

    public static function secret(Site $site, string $field): ?string
    {
        $stored = self::stored($site, $field);
        if ($stored === null) {
            return null;
        }
        $plain = self::decrypt($stored);
        if ($plain === null) {
            // Never log the value.
            Log::warning('UniFi controller secret of the site cannot be decrypted; enter it again on the site page.', ['site' => $site->slug, 'field' => $field]);
        }

        return $plain;
    }

    /**
     * Site settings with a new controller configuration; per secret: null keeps the stored one, '' removes it.
     *
     * @param  array<string, mixed>  $config  public fields (SITE_DEFAULTS keys)
     * @param  array<string, ?string>  $secrets  api_key / password
     * @return array<string, mixed>
     */
    public static function mergeInto(Site $site, array $config, #[\SensitiveParameter] array $secrets): array
    {
        $settings = $site->settings ?? [];
        $current = is_array($settings[self::SITE_SETTING] ?? null) ? $settings[self::SITE_SETTING] : [];
        $controller = array_merge(array_intersect_key($current, self::SITE_DEFAULTS), array_intersect_key($config, self::SITE_DEFAULTS));
        foreach (self::SECRETS as $field) {
            $new = $secrets[$field] ?? null;
            $stored = $new === null ? ($current[$field] ?? null) : ($new === '' ? null : app(DataKey::class)->encrypt($new));
            if (is_string($stored) && $stored !== '') {
                $controller[$field] = $stored;
            }
        }
        $settings[self::SITE_SETTING] = $controller;

        return $settings;
    }

    /** Re-encrypt the secrets with the current data key (after a rotation); number of sites rewritten. */
    public static function reencryptSecrets(): int
    {
        $count = 0;
        $current = app(DataKey::class)->currentId();
        foreach (Site::withTrashed()->get() as $site) {
            $settings = $site->settings ?? [];
            $changed = false;
            foreach (self::SECRETS as $field) {
                $stored = self::stored($site, $field);
                $plain = $stored === null ? null : self::decrypt($stored);
                if ($plain === null || DataKey::keyIdOf($stored) === $current) {
                    continue;
                }
                $settings[self::SITE_SETTING][$field] = app(DataKey::class)->encrypt($plain);
                $changed = true;
            }
            if ($changed) {
                $site->settings = $settings;
                $site->save();
                $count++;
            }
        }

        return $count;
    }

    private static function stored(Site $site, string $field): ?string
    {
        $stored = $site->setting(self::SITE_SETTING.'.'.$field);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    private static function decrypt(string $stored): ?string
    {
        try {
            return app(DataKey::class)->decrypt($stored);
        } catch (Throwable) {
            return null;
        }
    }
}
