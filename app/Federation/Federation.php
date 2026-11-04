<?php

namespace App\Federation;

/**
 * Registry of the tables replicated between federated instances (CONCEPT.md §10.3, §11).
 *
 * Only these tables record outbox events and accept remote events. Some local tables
 * (approval_requests, otp_challenges, session_summaries) carry the replication columns as well
 * but are not listed here, so they never leave the instance.
 */
final class Federation
{
    /**
     * Protocol version of events and heartbeats. Raise it when the event format or the meaning of
     * replicated columns changes incompatibly; a peer with a higher version is paused.
     */
    public const FEDERATION_PROTOCOL = 1;

    /**
     * Replicated tables in parent-before-child order, with their enforced foreign keys
     * (column => parent table). The apply engine waits for missing parents instead of failing.
     */
    public const TABLES = [
        'instances' => [],
        'users' => [],
        'sites' => [],
        // Routers: replicated for recovery; only the home instance of the site serves them.
        'nas_clients' => ['site_id' => 'sites'],
        'guests' => [],
        'settings' => [],
        // Certificate source per instance and target; each instance runs only its own.
        'certificate_sources' => [],
        'channels' => [],
        'terms_versions' => [],
        'site_codes' => ['site_id' => 'sites'],
        'grants' => [],
        'grant_site' => ['grant_id' => 'grants', 'site_id' => 'sites'],
        'devices' => ['grant_id' => 'grants', 'guest_id' => 'guests'],
        'terms_acceptances' => ['guest_id' => 'guests', 'terms_version_id' => 'terms_versions',
            'grant_id' => 'grants', 'site_id' => 'sites'],
        'mac_whitelist' => [],
        'mac_whitelist_site' => ['mac_whitelist_id' => 'mac_whitelist', 'site_id' => 'sites'],
        'audit_logs' => [],
    ];

    /**
     * Columns of replicated tables that stay local (status of the serving instance): left out of event
     * payloads, never overwritten by remote events.
     */
    public const LOCAL_COLUMNS = [
        // Guest Wi-Fi password kept on this instance only (flag setup.wifi_password_shared off), encrypted with APP_KEY
        // Guest Wi-Fi masters of the setup assistant (per Wi-Fi mode), local like the router API access
        // Guest SSID per CAP: selected radios (radio-mac) per CAPsMAN mode (decision 2026-11-02)
        'sites' => ['wifi_password_local', 'wifi_masters_local', 'wifi_caps_local'],
        'nas_clients' => ['last_seen_at', 'cert_fetched_at', 'login_page_fetched_at', 'router_state', 'router_state_at', 'router_state_error',
            'api_host', 'api_type', 'api_port', 'api_user', 'api_password', 'api_pin'],
    ];

    /**
     * Settings that control login or trust (CONCEPT.md §10.6, decision 2026-11-02): a remote event
     * changes them only when it comes from the hub; the hub setting itself has its own rule
     * (Applier::authorized(), hub takeover).
     */
    public const SECURITY_SETTINGS = [
        'federation_hub',              // Topology::HUB_SETTING
        'federation_offline_sms_only', // Topology::OFFLINE_SMS_ONLY_SETTING: logins while the hub is unreachable
        'require_2fa',                 // TwoFactor::REQUIRED_SETTING
        'oidc',                        // OidcSettings::KEY: issuer, client, role mapping, TOTP policy
        'oidc_client_secret',          // OidcSettings::SECRET_SETTING
        'admin_url',                   // approval links and OIDC redirect URI
        'telegram_owner',              // instance trusted to forward Telegram approval decisions
        'login_methods',               // LoginMethods::KEY: which guest login methods are offered
        'social_login',                // SocialSettings::KEY: social login providers and duration
        'social_secret_google',        // SocialSettings::secretSetting(): provider secrets
        'social_secret_apple',
        'social_secret_facebook',
        'social_secret_github',
        'account_login',               // DirectorySettings::KEY: default duration of a directory account login
    ];

    /**
     * Keys of `sites.settings` that decide where guest credentials go and which portal guests see: a
     * remote event changes them only from the hub or from the site's home instance. `directory`: the
     * server that receives the passwords of the account login (CONCEPT.md §7.2 E). `controller`: the UniFi
     * controller that receives the API key or the local account's password (CONCEPT.md §5.1). `generic`: the
     * mapping of a generic RADIUS captive portal that decides where the one-time credentials go (§5.1).
     */
    public const SECURITY_SITE_SETTINGS = ['hotspot_hosts', 'portal_url', 'directory', 'controller', 'generic'];

    /**
     * Columns of an active admin's user row that decide who can log in as that admin, or whether the
     * admin stays one (CONCEPT.md §10.6, decision 2026-11-02, extended 2026-11-03): a remote event changes
     * them only from the hub. Two more columns are checked by rule instead of by value:
     * `recovery_codes` may only lose codes (a code used on another instance), `totp_last_step` may not lie
     * in the future (it would block every TOTP code). `name` and `remember_token` replicate as before (the
     * remember cookie is encrypted with each instance's own APP_KEY).
     */
    public const ADMIN_LOGIN_COLUMNS = [
        'email',             // login name
        'password',          // password hash
        'role',              // demotion
        'deleted_at',        // deletion
        'totp_secret',       // TOTP secret (data key)
        'totp_confirmed_at', // 2FA enrolled
        'oidc_subject',      // OIDC identity bound to the account
    ];

    /** @return list<string> */
    public static function localColumns(string $table): array
    {
        return self::LOCAL_COLUMNS[$table] ?? [];
    }

    public static function isReplicated(string $table): bool
    {
        return array_key_exists($table, self::TABLES);
    }

    /** @return array<string, string> foreign key column => parent table */
    public static function foreignKeys(string $table): array
    {
        return self::TABLES[$table] ?? [];
    }
}
