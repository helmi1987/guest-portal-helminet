<?php

namespace App\Auth\Oidc;

use App\Approval\ApprovalSettings;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OIDC login settings for the admin UI (CONCEPT.md §9), replicated like all settings. The client
 * secret is stored encrypted under its own key and never leaves this class except for the token request.
 */
final class OidcSettings
{
    public const KEY = 'oidc';

    public const SECRET_SETTING = 'oidc_client_secret';

    public const TOTP_ALWAYS = 'always';

    public const TOTP_SKIP_IF_IDP_MFA = 'skip_if_idp_mfa';

    public const TOTP_NEVER = 'never';

    public const TOTP_MODES = [self::TOTP_ALWAYS, self::TOTP_SKIP_IF_IDP_MFA, self::TOTP_NEVER];

    public const DEFAULTS = [
        'enabled' => false,
        'label' => 'SSO',
        'issuer' => '',
        'client_id' => '',
        'scopes' => 'openid profile email groups',
        'groups_claim' => 'groups',
        'admin_group' => 'portal-admin',
        'manager_group' => 'portal-manager',
        'totp_mode' => self::TOTP_SKIP_IF_IDP_MFA,
        'acr' => '',
        // Seconds since the authentication at the provider (auth_time) for skipping the portal TOTP; null: off.
        'max_auth_age' => null,
        // First OIDC login links an existing local user with the same, verified email.
        'link_by_email' => true,
    ];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $stored = Setting::get(self::KEY, []);

        return array_merge(self::DEFAULTS, is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : []);
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** Enabled and complete enough to start a login. */
    public static function enabled(): bool
    {
        $config = self::all();

        return (bool) $config['enabled'] && $config['issuer'] !== '' && $config['client_id'] !== '';
    }

    public static function hasClientSecret(): bool
    {
        return self::clientSecret() !== null;
    }

    /** A secret is stored but cannot be decrypted (e.g. other APP_KEY): it must be entered again. */
    public static function clientSecretBroken(): bool
    {
        return self::storedSecret() !== null && self::decrypt(self::storedSecret()) === null;
    }

    public static function clientSecret(): ?string
    {
        $stored = self::storedSecret();
        if ($stored === null) {
            return null;
        }
        $secret = self::decrypt($stored);
        if ($secret === null) {
            // Never log the value.
            Log::warning('OIDC client secret cannot be decrypted; enter it again in the settings.');
        }

        return $secret;
    }

    private static function storedSecret(): ?string
    {
        $stored = Setting::get(self::SECRET_SETTING);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    private static function decrypt(string $stored): ?string
    {
        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  string|null  $secret  new secret; null keeps the stored one, '' removes it
     */
    public static function save(array $config, ?string $secret): void
    {
        Setting::put(self::KEY, array_merge(self::DEFAULTS, array_intersect_key($config, self::DEFAULTS)));
        if ($secret !== null) {
            Setting::put(self::SECRET_SETTING, $secret === '' ? null : Crypt::encryptString($secret));
        }
    }

    /** Redirect URI to register at the identity provider (admin port, from the admin URL setting). */
    public static function redirectUri(): string
    {
        return ApprovalSettings::adminUrl().route('oidc.callback', [], false);
    }
}
