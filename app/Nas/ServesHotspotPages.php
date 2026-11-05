<?php

namespace App\Nas;

use App\Models\Site;

/**
 * Optional NasDriver interface (decision 2026-11-05, v0.29.0): the NAS hosts more pages than the login page and
 * the portal generates them (MikroTik: login.html, status.html, logout.html, alogin.html in the hotspot
 * html-directory). Drivers without it only have loginPage() (page "login").
 */
interface ServesHotspotPages
{
    /** Page names in fetch order; served as /router-login/{nas}/{page}.html. */
    public const PAGES = ['login', 'status', 'logout', 'alogin'];

    /**
     * Generated pages, page name => HTML (every name of PAGES).
     *
     * @return array<string, string>
     */
    public function hotspotPages(Site $site): array;

    /** Login URL of the NAS for a new login after a logout (as the redirect would send it), or null. */
    public function hotspotLoginUrl(Site $site): ?string;
}
