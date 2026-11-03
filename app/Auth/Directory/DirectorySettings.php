<?php

namespace App\Auth\Directory;

use App\Federation\DataKey;
use App\Models\Setting;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Directory account login (CONCEPT.md §7.2 E, decision 2026-11-03, v0.24.0): per site an Active Directory
 * or LDAP server in the replicated site setting `directory` (a security site setting, Federation::
 * SECURITY_SITE_SETTINGS); the service account password in it is encrypted with the federation data key
 * (CONCEPT.md §10.2) and never leaves this class except for the bind. The global default access duration
 * lives in the hub-only setting `account_login`.
 */
final class DirectorySettings
{
    /** Global setting (Federation::SECURITY_SETTINGS): default access duration of an account login. */
    public const KEY = 'account_login';

    /** Site setting key. */
    public const SITE_SETTING = 'directory';

    public const TLS_LDAPS = 'ldaps';

    public const TLS_STARTTLS = 'starttls';

    public const TLS_NONE = 'none';

    public const TLS_MODES = [self::TLS_LDAPS, self::TLS_STARTTLS, self::TLS_NONE];

    public const DEFAULT_FILTER = '(&(objectClass=user)(sAMAccountName={username}))';

    public const DEFAULT_DURATION_S = 86400;

    /** Bounds of an access duration: 5 minutes to 30 days. */
    public const MIN_DURATION_S = 300;

    public const MAX_DURATION_S = 30 * 86400;

    /** Fields of the site setting besides the encrypted bind_password; duration_s null = global default. */
    public const SITE_DEFAULTS = [
        'enabled' => false,
        'host' => '',
        'port' => 636,
        'tls' => self::TLS_LDAPS,
        'verify' => true,
        'ca_cert' => '',
        'base_dn' => '',
        'bind_dn' => '',
        'user_filter' => self::DEFAULT_FILTER,
        'group_dn' => '',
        'name_attributes' => ['displayName'],
        'email_attribute' => 'mail',
        'timeout_s' => 5,
        'duration_s' => null,
    ];

    /** Global default access duration (seconds). */
    public static function durationS(): int
    {
        $stored = Setting::get(self::KEY, []);
        $value = is_array($stored) ? (int) ($stored['duration_s'] ?? self::DEFAULT_DURATION_S) : self::DEFAULT_DURATION_S;

        return self::clampDuration($value);
    }

    public static function saveDurationS(int $seconds): void
    {
        Setting::put(self::KEY, ['duration_s' => self::clampDuration($seconds)]);
    }

    public static function clampDuration(int $seconds): int
    {
        return max(self::MIN_DURATION_S, min(self::MAX_DURATION_S, $seconds));
    }

    /**
     * Public fields of a site's directory (defaults merged, types normalised, no password).
     *
     * @return array{enabled: bool, host: string, port: int, tls: string, verify: bool, ca_cert: string, base_dn: string,
     *     bind_dn: string, user_filter: string, group_dn: string, name_attributes: list<string>, email_attribute: string,
     *     timeout_s: int, duration_s: int|null}
     */
    public static function forSite(Site $site): array
    {
        $stored = $site->setting(self::SITE_SETTING, []);
        $stored = is_array($stored) ? array_intersect_key($stored, self::SITE_DEFAULTS) : [];
        $c = array_merge(self::SITE_DEFAULTS, $stored);
        $names = array_values(array_filter(array_map(fn ($n) => is_string($n) ? trim($n) : '', (array) $c['name_attributes'])));

        return [
            'enabled' => (bool) $c['enabled'],
            'host' => (string) $c['host'],
            'port' => max(1, min(65535, (int) $c['port'])),
            'tls' => in_array($c['tls'], self::TLS_MODES, true) ? $c['tls'] : self::TLS_LDAPS,
            'verify' => (bool) $c['verify'],
            'ca_cert' => (string) $c['ca_cert'],
            'base_dn' => (string) $c['base_dn'],
            'bind_dn' => (string) $c['bind_dn'],
            'user_filter' => (string) $c['user_filter'] !== '' ? (string) $c['user_filter'] : self::DEFAULT_FILTER,
            'group_dn' => (string) $c['group_dn'],
            'name_attributes' => $names !== [] ? $names : self::SITE_DEFAULTS['name_attributes'],
            'email_attribute' => (string) $c['email_attribute'],
            'timeout_s' => max(1, min(30, (int) $c['timeout_s'])),
            'duration_s' => $c['duration_s'] === null || $c['duration_s'] === '' ? null : self::clampDuration((int) $c['duration_s']),
        ];
    }

    /**
     * Complete enough to try a login: enabled, host and base DN set, user filter with {username}, and a
     * readable password when a service account is set (empty bind DN = anonymous search).
     */
    public static function isConfigured(Site $site): bool
    {
        $c = self::forSite($site);

        return $c['enabled'] && $c['host'] !== '' && $c['base_dn'] !== '' && str_contains($c['user_filter'], '{username}')
            && ($c['bind_dn'] === '' || self::password($site) !== null);
    }

    /** Some site has a configured directory (settings hint, login method without a site). */
    public static function anyConfigured(): bool
    {
        return Site::query()->get()->contains(fn (Site $site) => self::isConfigured($site));
    }

    /** Access duration of an account login at this site: the site's value or the global default. */
    public static function siteDurationS(Site $site): int
    {
        return self::forSite($site)['duration_s'] ?? self::durationS();
    }

    /** Connection settings with the decrypted service account password. */
    public static function config(Site $site): DirectoryConfig
    {
        $c = self::forSite($site);

        return new DirectoryConfig($c['host'], $c['port'], $c['tls'], $c['verify'], $c['ca_cert'], $c['base_dn'], $c['bind_dn'],
            $c['bind_dn'] !== '' ? (self::password($site) ?? '') : '', $c['user_filter'], $c['group_dn'], $c['name_attributes'],
            $c['email_attribute'], $c['timeout_s']);
    }

    public static function hasPassword(Site $site): bool
    {
        return self::storedPassword($site) !== null;
    }

    /** A password is stored but cannot be decrypted (e.g. unknown data key): it must be entered again. */
    public static function passwordBroken(Site $site): bool
    {
        $stored = self::storedPassword($site);

        return $stored !== null && self::decrypt($stored) === null;
    }

    public static function password(Site $site): ?string
    {
        $stored = self::storedPassword($site);
        if ($stored === null) {
            return null;
        }
        $plain = self::decrypt($stored);
        if ($plain === null) {
            // Never log the value.
            Log::warning('Directory service account password of the site cannot be decrypted; enter it again on the site page.', ['site' => $site->slug]);
        }

        return $plain;
    }

    /** Encrypted value for the site setting; null or '' removes it. */
    public static function encryptPassword(#[\SensitiveParameter] ?string $password): ?string
    {
        return $password === null || $password === '' ? null : app(DataKey::class)->encrypt($password);
    }

    /**
     * Site settings with a new directory configuration; $password: null keeps the stored one, '' removes it.
     *
     * @param  array<string, mixed>  $config  public fields (SITE_DEFAULTS keys)
     * @return array<string, mixed>
     */
    public static function mergeInto(Site $site, array $config, #[\SensitiveParameter] ?string $password): array
    {
        $settings = $site->settings ?? [];
        $current = is_array($settings[self::SITE_SETTING] ?? null) ? $settings[self::SITE_SETTING] : [];
        $directory = array_merge(array_intersect_key($current, self::SITE_DEFAULTS), array_intersect_key($config, self::SITE_DEFAULTS));
        $stored = $password === null ? ($current['bind_password'] ?? null) : self::encryptPassword($password);
        if (is_string($stored) && $stored !== '') {
            $directory['bind_password'] = $stored;
        }
        $settings[self::SITE_SETTING] = $directory;

        return $settings;
    }

    /** Re-encrypt the service account passwords with the current data key (after a rotation); number rewritten. */
    public static function reencryptPasswords(): int
    {
        $count = 0;
        foreach (Site::withTrashed()->get() as $site) {
            $stored = self::storedPassword($site);
            $plain = $stored === null ? null : self::decrypt($stored);
            if ($plain === null || DataKey::keyIdOf($stored) === app(DataKey::class)->currentId()) {
                continue;
            }
            $settings = $site->settings ?? [];
            $settings[self::SITE_SETTING]['bind_password'] = self::encryptPassword($plain);
            $site->settings = $settings;
            $site->save();
            $count++;
        }

        return $count;
    }

    private static function storedPassword(Site $site): ?string
    {
        $stored = $site->setting(self::SITE_SETTING.'.bind_password');

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
