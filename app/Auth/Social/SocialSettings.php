<?php

namespace App\Auth\Social;

use App\Federation\DataKey;
use App\Models\Setting;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Social login providers for guests (CONCEPT.md §7.2 D, decision 2026-11-03): Google, Apple, Facebook,
 * GitHub. Public settings live in one replicated, hub-only setting (Federation::SECURITY_SETTINGS);
 * every provider secret (client secret, for Apple the private key) is stored under its own key,
 * encrypted with the federation data key (CONCEPT.md §10.2), and never leaves this class except for
 * the token request.
 */
final class SocialSettings
{
    public const KEY = 'social_login';

    public const GOOGLE = 'google';

    public const APPLE = 'apple';

    public const FACEBOOK = 'facebook';

    public const GITHUB = 'github';

    public const PROVIDERS = [self::GOOGLE, self::APPLE, self::FACEBOOK, self::GITHUB];

    /** Setting key of a provider secret. */
    public static function secretSetting(string $provider): string
    {
        return 'social_secret_'.$provider;
    }

    /** @return list<string> all secret setting keys (federation security settings) */
    public static function secretSettings(): array
    {
        return array_map(self::secretSetting(...), self::PROVIDERS);
    }

    /** Public fields per provider (secrets excluded). */
    public const PROVIDER_DEFAULTS = [
        self::GOOGLE => ['enabled' => false, 'client_id' => ''],
        // Apple: services ID as client ID; the client secret is a JWT signed with the .p8 key (team + key ID).
        self::APPLE => ['enabled' => false, 'client_id' => '', 'team_id' => '', 'key_id' => ''],
        self::FACEBOOK => ['enabled' => false, 'client_id' => ''],
        self::GITHUB => ['enabled' => false, 'client_id' => ''],
    ];

    public const DEFAULTS = [
        // Access duration of a social login grant, time running from the login (like SMS).
        'duration_s' => 86400,
        'providers' => self::PROVIDER_DEFAULTS,
    ];

    /** @return array{duration_s: int, providers: array<string, array<string, mixed>>} */
    public static function all(): array
    {
        $stored = Setting::get(self::KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $providers = [];
        foreach (self::PROVIDER_DEFAULTS as $name => $defaults) {
            $own = $stored['providers'][$name] ?? [];
            $providers[$name] = array_merge($defaults, is_array($own) ? array_intersect_key($own, $defaults) : []);
        }

        return [
            'duration_s' => max(60, (int) ($stored['duration_s'] ?? self::DEFAULTS['duration_s'])),
            'providers' => $providers,
        ];
    }

    /** @return array<string, mixed> public fields of one provider */
    public static function provider(string $provider): array
    {
        return self::all()['providers'][$provider] ?? [];
    }

    public static function durationS(): int
    {
        return self::all()['duration_s'];
    }

    /** Enabled and complete enough to start a login (client ID and a readable secret, Apple also team and key ID). */
    public static function isReady(string $provider): bool
    {
        $config = self::provider($provider);
        if ($config === [] || ! $config['enabled'] || $config['client_id'] === '' || self::secret($provider) === null) {
            return false;
        }

        return $provider !== self::APPLE || ($config['team_id'] !== '' && $config['key_id'] !== '');
    }

    /** @return list<string> providers offered to guests, in display order */
    public static function enabledProviders(): array
    {
        return array_values(array_filter(self::PROVIDERS, self::isReady(...)));
    }

    public static function hasSecret(string $provider): bool
    {
        return self::secret($provider) !== null;
    }

    /** A secret is stored but cannot be decrypted (e.g. unknown data key): it must be entered again. */
    public static function secretBroken(string $provider): bool
    {
        $stored = self::storedSecret($provider);

        return $stored !== null && self::decrypt($stored) === null;
    }

    public static function secret(string $provider): ?string
    {
        $stored = self::storedSecret($provider);
        if ($stored === null) {
            return null;
        }
        $secret = self::decrypt($stored);
        if ($secret === null) {
            // Never log the value.
            Log::warning('Social login secret cannot be decrypted; enter it again in the settings.', ['provider' => $provider]);
        }

        return $secret;
    }

    private static function storedSecret(string $provider): ?string
    {
        $stored = Setting::get(self::secretSetting($provider));

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

    /**
     * @param  array{duration_s?: int, providers?: array<string, array<string, mixed>>}  $config
     * @param  array<string, string|null>  $secrets  provider => new secret; null keeps the stored one, '' removes it
     */
    public static function save(array $config, array $secrets = []): void
    {
        $current = self::all();
        $providers = [];
        foreach (self::PROVIDER_DEFAULTS as $name => $defaults) {
            $own = $config['providers'][$name] ?? [];
            $providers[$name] = array_merge($current['providers'][$name], is_array($own) ? array_intersect_key($own, $defaults) : []);
            $providers[$name]['enabled'] = (bool) $providers[$name]['enabled'];
        }
        Setting::put(self::KEY, [
            'duration_s' => max(60, (int) ($config['duration_s'] ?? $current['duration_s'])),
            'providers' => $providers,
        ]);
        foreach (self::PROVIDERS as $name) {
            if (array_key_exists($name, $secrets) && $secrets[$name] !== null) {
                Setting::put(self::secretSetting($name), $secrets[$name] === '' ? null : app(DataKey::class)->encrypt($secrets[$name]));
            }
        }
    }

    /** Re-encrypt the stored secrets with the current data key (after a rotation); number rewritten. */
    public static function reencryptSecrets(): int
    {
        $count = 0;
        foreach (self::PROVIDERS as $provider) {
            $stored = self::storedSecret($provider);
            $secret = $stored === null ? null : self::decrypt($stored);
            if ($secret === null || DataKey::keyIdOf($stored) === app(DataKey::class)->currentId()) {
                continue;
            }
            Setting::put(self::secretSetting($provider), app(DataKey::class)->encrypt($secret));
            $count++;
        }

        return $count;
    }

    /**
     * Guest hosts the portal is reached under: APP_URL and the distinct portal URLs of the sites (scheme
     * and host, no path). The callback must come back to the host the guest's session lives on, so every
     * one of these origins has to be registered at the provider (shown in the settings).
     *
     * @return list<string> origins like "https://portal.example.com"
     */
    public static function origins(): array
    {
        $origins = [];
        $urls = [config('app.url')];
        foreach (Site::query()->get() as $site) {
            $urls[] = $site->setting('portal_url');
        }
        foreach ($urls as $url) {
            if (! is_string($url) || ($parts = parse_url($url)) === false || empty($parts['host'])) {
                continue;
            }
            $scheme = $parts['scheme'] ?? 'https';
            $origins[] = strtolower($scheme.'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        return array_values(array_unique($origins));
    }

    /**
     * Redirect URIs to register at a provider, one per guest origin (route `guest.social.callback`,
     * `/portal/social/{provider}/callback`).
     *
     * @return list<string>
     */
    public static function redirectUris(string $provider): array
    {
        $path = '/portal/social/'.$provider.'/callback';

        return array_map(fn (string $origin) => $origin.$path, self::origins());
    }
}
