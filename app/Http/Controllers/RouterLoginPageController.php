<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesRouter;
use App\Models\Site;
use App\Nas\RouterFreshness;
use App\Nas\ServesHotspotPages;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generated hotspot pages for /tool fetch on the router (setup assistant, CONCEPT.md §9): login.html, and for
 * drivers with ServesHotspotPages also status.html, logout.html and alogin.html (decision 2026-11-05, v0.29.0).
 * Same authentication as the router certificate download (token + NAS IP); a page the driver does not have is
 * 404 (after the authentication, so unknown routers still get 403). Every delivered page is recorded with its
 * fingerprint (decision 2026-11-05, v0.30.0): a page whose current content differs is outdated (RouterFreshness).
 */
class RouterLoginPageController extends Controller
{
    use AuthorizesRouter;

    public function __invoke(Request $request, RouterFreshness $freshness, string $nas, string $page): Response
    {
        $nas = $this->authorizeRouter($request, $nas, 'Router hotspot page download');
        $html = self::pages($nas->site)[$page] ?? null;
        abort_if($html === null, 404);
        $nas->recordHotspotPage($page, RouterFreshness::hash($html));
        $freshness->resetWhenFresh($nas);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Hotspot pages of the site's driver, page name => HTML: all of ServesHotspotPages, else the login page only.
     *
     * @return array<string, string>
     */
    public static function pages(Site $site): array
    {
        $driver = $site->driver();

        return $driver instanceof ServesHotspotPages ? $driver->hotspotPages($site) : ['login' => $driver->loginPage($site)];
    }
}
