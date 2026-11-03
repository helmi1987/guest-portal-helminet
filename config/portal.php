<?php

// Persistent data folder (container: /data; development: storage/app).
$dataDir = rtrim((string) env('PORTAL_DATA_DIR', storage_path('app')), '/');

return [

    /*
    | Zone used when the web server does not pass PORTAL_ZONE (e.g. `php artisan serve`).
    | Leave empty in production: nginx sets the zone per listener (guest/admin/internal).
    | "all" disables the zone check, local development only (ignored when APP_ENV=production).
    */
    'default_zone' => env('PORTAL_DEFAULT_ZONE'),

    // Shared secret for the loopback-only internal API (FreeRADIUS -> Laravel).
    'internal_api_token' => env('INTERNAL_API_TOKEN'),

    // Host name of the guest and admin ports (certificate check of the portal certificate pull);
    // empty: host of APP_URL.
    'hostname' => env('PORTAL_HOSTNAME'),

    // Time zone for times shown in the admin UI, notifications and backup file names, and for
    // admin input. Storage, expiry logic and the scheduler stay in UTC (app.timezone).
    'display_timezone' => env('APP_TIMEZONE', env('TZ', 'UTC')),

    // Admin listener port (nginx, env ADMIN_PORT); used for the admin URL fallback (APP_URL + this port).
    'admin_port' => (int) env('ADMIN_PORT', 8443),

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
        // An open session without accounting update for this long is shown as stale (lost Stop).
        'stale_after' => (int) env('PORTAL_RADIUS_STALE_AFTER', 3 * (int) env('PORTAL_RADIUS_INTERIM', 300)),
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

    // Notice on the login page when a returning device's access ended (expired, revoked, disconnected)
    // within this many hours (CONCEPT.md §7.1).
    'ended_notice_hours' => (int) env('PORTAL_ENDED_NOTICE_HOURS', 24),

    // Router certificate for login-by=https (CONCEPT.md §6), offered to the routers for download.
    // Mounted by the operator (container: /data/certs/router.crt + router.key).
    'router_cert' => [
        'cert' => env('PORTAL_ROUTER_CERT', $dataDir.'/certs/router.crt'),
        'key' => env('PORTAL_ROUTER_KEY', $dataDir.'/certs/router.key'),
        // Router downloads (certificate, login.html) only from the router's NAS IP. Always on, also with
        // TRUSTED_PROXIES (routers reach the guest port directly); PORTAL_ROUTER_IP_CHECK=false is the only
        // way to switch it off (portal:init then logs a warning at container start).
        'check_source_ip' => (bool) env('PORTAL_ROUTER_IP_CHECK', true),
        // RouterOS scheduler interval for fetching the certificate.
        'fetch_interval' => env('PORTAL_ROUTER_CERT_INTERVAL', '1d'),
    ],

    // Read-only router API of the setup assistant (CONCEPT.md §9): state query per router. The assistant's
    // commands are greyed out when the last query is older than max_age_minutes or than the last change of
    // the setup inputs. Timeouts in seconds; deadline = whole query of one router ("Refresh" queues one job
    // per router on the low-priority queue "router-state"). max_bytes = response size cap (API sentences in
    // total, REST body); max_word = one API word.
    'router_api' => [
        'max_age_minutes' => (int) env('PORTAL_ROUTER_STATE_MAX_AGE', 15),
        'connect_timeout' => 5,
        'timeout' => 10,
        'deadline' => 45,
        'max_rows' => 2000,
        'max_bytes' => 8388608,
        'max_word' => 65536,
    ],

    // Certificate for the guest and admin ports (CONCEPT.md §6), selected by portal-certs (nginx).
    'portal_cert' => [
        'cert' => $dataDir.'/certs/portal.crt',
        'key' => $dataDir.'/certs/portal.key',
    ],

    // Certificate pull from CertWarden or a generic URL (CONCEPT.md §6): HTTP timeout, max. size.
    'cert_pull' => [
        'timeout' => 20,
        'max_bytes' => 1048576,
    ],

    // Backups (CONCEPT.md §9): SQLite snapshot + env file + certificates as one tar.gz, kept N times
    // (default for the local setting backup_keep). Never inside a web-reachable folder.
    'backup' => [
        'dir' => env('PORTAL_BACKUP_DIR', $dataDir.'/backups'),
        'keep' => 14,
        'env_file' => env('PORTAL_DATA_DIR') ? $dataDir.'/.env' : base_path('.env'),
        'certs_dir' => $dataDir.'/certs',
        // Extracted with a backup into the data folder: marks a restore (federation resync, CONCEPT.md §10.3).
        'restore_marker' => $dataDir.'/backup-manifest.json',
        // SQLite file to snapshot; null = the sqlite connection's database.
        'database' => null,
    ],

    // Branding (CONCEPT.md §9, docs/branding.md): uploaded logo/favicon files, stored by content hash
    // next to the other persistent data (included in backups). Limits for uploads and custom CSS.
    'branding' => [
        'dir' => env('PORTAL_BRANDING_DIR', $dataDir.'/branding'),
        'logo_max_bytes' => 524288,
        'favicon_max_bytes' => 102400,
        'max_pixels' => 4096,
        'css_max_bytes' => 65536,
    ],

    // Push approval: max. open requests per site (beyond that guests are asked to try later).
    'approval' => [
        'max_pending_per_site' => (int) env('PORTAL_APPROVAL_MAX_PENDING', 10),
    ],

    // SMS login (CONCEPT.md §7.2 B). Duration, countries and caps are defaults for the replicated
    // settings sms_duration_s, sms_countries and sms_caps (admin UI).
    'sms' => [
        'duration_s' => 86400,
        'countries' => ['CH', 'DE', 'AT', 'IT', 'FR'],
        'ttl_minutes' => 10,
        'max_attempts' => 3,
        // Sends per code request (first SMS + resends) and minimum gap between them.
        'max_sends' => 3,
        'resend_after_s' => 60,
        // Cost caps: max. SMS (first SMS and resends) per phone number, device (MAC) and site per
        // hour/day, and per instance per day.
        'caps' => ['phone_hour' => 3, 'phone_day' => 6, 'mac_hour' => 3, 'mac_day' => 6, 'site_hour' => 30,
            'site_day' => 200, 'global_day' => 500],
    ],

    // Code duration presets in seconds.
    'duration_presets' => ['8h' => 28800, '1d' => 86400, '1w' => 604800],

    // Default retention period (days); overridable by the replicated setting "retention_days".
    'retention_days' => 365,

    // Federation (CONCEPT.md §10).
    'federation' => [
        // Remote HLC versions further ahead of the local clock than this are rejected (clock skew).
        'max_future_ms' => (int) env('PORTAL_FEDERATION_MAX_FUTURE_MS', 300000),
        // An event waiting for a missing parent row pauses its origin after this many attempts or minutes.
        'max_wait_attempts' => (int) env('PORTAL_FEDERATION_MAX_WAIT_ATTEMPTS', 50),
        'max_wait_minutes' => (int) env('PORTAL_FEDERATION_MAX_WAIT_MINUTES', 1440),
        // Own key + self-signed certificate for mTLS (generated by portal-certs at container start).
        'dir' => env('PORTAL_FEDERATION_DIR', $dataDir.'/federation'),
        // Federation listener port (nginx, env FEDERATION_PORT).
        'port' => (int) env('FEDERATION_PORT', 9443),
        // URL under which the other instances reach this one (e.g. its WireGuard address); default
        // for the instance's federation URL, editable on the federation page.
        'url' => env('PORTAL_FEDERATION_URL'),
        // Peer calls: short timeouts, no redirects.
        'connect_timeout' => 3,
        'timeout' => 10,
        // Live queries for the admin UI (sessions of other instances, remote disconnect).
        'live_timeout' => 4,
        // Pairing: lifetime of a join token (minutes).
        'join_token_minutes' => 15,
        // A non-hub instance treats the hub as unreachable after this many seconds without a
        // successful heartbeat (limited mode: new logins only by SMS or site code).
        'hub_unreachable_after' => (int) env('PORTAL_FEDERATION_HUB_UNREACHABLE_AFTER', 120),
        // Clock skew to a peer: warning above, replication with the peer paused above (milliseconds).
        'skew_warn_ms' => (int) env('PORTAL_FEDERATION_SKEW_WARN_MS', 30000),
        'skew_pause_ms' => (int) env('PORTAL_FEDERATION_SKEW_PAUSE_MS', 300000),
        // Events per page (push and pull).
        'batch' => 500,
    ],

    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',

];
