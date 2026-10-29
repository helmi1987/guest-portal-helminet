<?php

namespace App\Http\Controllers;

use App\Branding\BrandingAssets;
use App\Branding\BrandingSettings;
use App\Models\Site;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branding files and theme stylesheets (guest and admin port, stateless; docs/branding.md).
 */
class BrandingController extends Controller
{
    /** Uploaded logo/favicon by content hash: cacheable forever, sandboxed (no scripts even when opened directly). */
    public function file(BrandingAssets $assets, string $file): BinaryFileResponse
    {
        $path = $assets->path($file);
        abort_if($path === null, 404);
        preg_match(BrandingAssets::FILE_PATTERN, $file, $m);

        return response()->file($path, [
            'Content-Type' => BrandingAssets::mime($m[2]),
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    /**
     * Colour variables + custom CSS of the global branding or a site with own colours/CSS. Long
     * caching only when the requested version matches the current content.
     */
    public function theme(Request $request, string $scope): Response
    {
        $site = null;
        if ($scope !== 'global') {
            $site = Site::find($scope);
            abort_if($site === null, 404);
        }
        $css = BrandingSettings::resolve($site)->css();
        $current = substr(hash('sha256', $css), 0, 16);

        return response($css, 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $request->query('v') === $current ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
