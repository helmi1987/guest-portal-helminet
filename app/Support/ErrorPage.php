<?php

namespace App\Support;

use App\Branding\Branding;
use App\Branding\BrandingSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * View data for the HTML error pages (resources/views/errors): area (guest/admin look), locale,
 * branding and the back link. Never touches the database for 5xx errors and never fails itself:
 * any problem falls back to the built-in branding.
 */
final class ErrorPage
{
    /** Status codes that get a dedicated text; everything else uses the generic one. */
    public const KNOWN = [403, 404, 419, 429, 500, 503];

    /**
     * @return array{area: string, status: int, kind: string, branding: Branding, back: string, retryAfter: ?int}
     */
    public static function data(int $status, ?Throwable $exception = null): array
    {
        $request = request();
        $area = self::area($request);
        self::applyLocale($request);

        return [
            'area' => $area,
            'status' => $status,
            'kind' => in_array($status, self::KNOWN, true) ? (string) $status : ($status >= 500 ? '500' : 'generic'),
            'branding' => self::branding($request, $area, $status),
            'back' => $area === Zone::ADMIN ? '/admin' : '/portal',
            'retryAfter' => self::retryAfter($exception),
        ];
    }

    private static function area(Request $request): string
    {
        $zone = Zone::of($request);
        if ($zone === Zone::ADMIN || (! in_array($zone, [Zone::GUEST, Zone::ADMIN], true) && $request->is('admin', 'admin/*'))) {
            return Zone::ADMIN;
        }

        return Zone::GUEST;
    }

    /** Session choice (language switcher), else Accept-Language; the route's SetLocale may not have run. */
    private static function applyLocale(Request $request): void
    {
        try {
            $supported = (array) config('portal.locales');
            $locale = $request->hasSession() ? $request->session()->get('locale') : null;
            if (! in_array($locale, $supported, true)) {
                $locale = $request->getPreferredLanguage($supported) ?? config('app.locale');
            }
            app()->setLocale($locale);
        } catch (Throwable) {
            // Keep the current locale.
        }
    }

    private static function branding(Request $request, string $area, int $status): Branding
    {
        // Server errors may be caused by the database itself: built-in look only, no queries.
        if ($status >= 500) {
            return BrandingSettings::builtIn();
        }
        try {
            return $area === Zone::ADMIN ? BrandingSettings::forAdmin() : BrandingSettings::forGuest($request);
        } catch (Throwable) {
            return BrandingSettings::builtIn();
        }
    }

    private static function retryAfter(?Throwable $exception): ?int
    {
        if (! $exception instanceof HttpExceptionInterface) {
            return null;
        }
        $value = $exception->getHeaders()['Retry-After'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
