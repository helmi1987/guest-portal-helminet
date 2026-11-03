<?php

namespace App\Http\Controllers;

use App\Support\Zone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Language switcher of both zones: stores the locale in the zone's own session (the guest and the
 * admin session are separate cookies, so each zone has its own route and switch).
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('portal.locales'), true), 404);

        $request->session()->put('locale', $locale);

        // Return to the page the switcher was on (captive browsers often send no Referer).
        $to = (string) $request->query('r');
        if (str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, '\\')) {
            return redirect()->to($to);
        }

        $home = Zone::of($request) === Zone::ADMIN ? route('admin.dashboard') : route('guest.portal');

        return redirect()->back(fallback: $home);
    }
}
