<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        return redirect()->back(fallback: route('guest.portal'));
    }
}
