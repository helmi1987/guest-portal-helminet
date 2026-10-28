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

    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',

];
