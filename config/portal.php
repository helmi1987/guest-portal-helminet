<?php

return [

    /*
    | Zone used when the web server does not pass PORTAL_ZONE (e.g. `php artisan serve`).
    | Leave empty in production: nginx sets the zone per listener (guest/admin/internal).
    | "all" disables the zone check, local development only (ignored when APP_ENV=production).
    */
    'default_zone' => env('PORTAL_DEFAULT_ZONE'),

    // Shared secret for the loopback-only internal API (FreeRADIUS -> Laravel).
    'internal_api_token' => env('INTERNAL_API_TOKEN'),

    // Display name of this instance (federation, later milestones).
    'instance_name' => env('PORTAL_INSTANCE_NAME', 'Portal'),

    // Supported UI languages; the first one matching the browser wins.
    'locales' => ['de', 'it', 'fr', 'en'],

    // RADIUS (CONCEPT.md §5).
    'radius' => [
        // FreeRADIUS clients file rendered from the routers (container: /data/radius/clients.conf).
        'clients_file' => env('PORTAL_RADIUS_CLIENTS_FILE', env('PORTAL_DATA_DIR')
            ? rtrim((string) env('PORTAL_DATA_DIR'), '/').'/radius/clients.conf'
            : storage_path('app/radius/clients.conf')),
        // Lifetime of the one-time credentials handed to the router.
        'token_ttl' => 60,
        'interim_interval' => (int) env('PORTAL_RADIUS_INTERIM', 300),
        // radclient (freeradius-utils) sends Disconnect-Request (RFC 5176).
        'radclient' => env('PORTAL_RADCLIENT', 'radclient'),
        'disconnect_timeout' => 3,
        'disconnect_retries' => 2,
    ],

    // Guest login brute-force protection: failures allowed within the window (CONCEPT.md §8).
    'login_limits' => [
        'window_minutes' => (int) env('PORTAL_LOGIN_WINDOW', 15),
        'per_mac' => (int) env('PORTAL_LOGIN_MAX_PER_MAC', 5),
        'per_ip' => (int) env('PORTAL_LOGIN_MAX_PER_IP', 10),
        'global' => (int) env('PORTAL_LOGIN_MAX_GLOBAL', 200),
    ],

    // Router certificate for login-by=https (CONCEPT.md §6), offered to the routers for download.
    // Mounted by the operator (container: /data/certs/router.crt + router.key).
    'router_cert' => [
        'cert' => env('PORTAL_ROUTER_CERT', rtrim((string) env('PORTAL_DATA_DIR', storage_path('app')), '/').'/certs/router.crt'),
        'key' => env('PORTAL_ROUTER_KEY', rtrim((string) env('PORTAL_DATA_DIR', storage_path('app')), '/').'/certs/router.key'),
        // RouterOS scheduler interval for fetching the certificate.
        'fetch_interval' => env('PORTAL_ROUTER_CERT_INTERVAL', '1d'),
    ],

    // Code duration presets in seconds.
    'duration_presets' => ['8h' => 28800, '1d' => 86400, '1w' => 604800],

    // Default retention period (days); overridable by the replicated setting "retention_days".
    'retention_days' => 365,

    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',

];
