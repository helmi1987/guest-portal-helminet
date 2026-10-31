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
        'sites' => ['wifi_password_local'],
        'nas_clients' => ['last_seen_at', 'cert_fetched_at', 'login_page_fetched_at', 'router_state', 'router_state_at', 'router_state_error',
            'api_host', 'api_type', 'api_port', 'api_user', 'api_password', 'api_pin'],
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
