<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale from the session (language switcher), else from the browser (Accept-Language).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('portal.locales');
        $locale = $request->session()->get('locale');

        if (! in_array($locale, $supported, true)) {
            $locale = $request->getPreferredLanguage($supported) ?? config('app.locale');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
