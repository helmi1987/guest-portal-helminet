<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TermsVersion;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Terms of use per language, versioned (admin only, CONCEPT.md §9). A changed text is a new version.
 */
class TermsController extends Controller
{
    /** ?default=<locale> opens that card with the default text (JS-free "load default text"). */
    public function index(Request $request): View
    {
        $versions = TermsVersion::query()->withCount('acceptances')->orderByDesc('number')->get()->groupBy('locale');
        $locales = config('portal.locales');
        $load = in_array($request->query('default'), $locales, true) ? $request->query('default') : null;

        return view('admin.terms', [
            'versions' => $versions,
            'load' => $load,
            'defaults' => collect($locales)->mapWithKeys(fn (string $l) => [$l => TermsVersion::defaultText($l)]),
            'missing' => array_values(array_filter($locales, fn (string $l) => ! $versions->has($l))),
        ]);
    }

    /** Publish the default text as version 1 for every language without a version. */
    public function publishDefaults(): RedirectResponse
    {
        $published = TermsVersion::publishDefaults();

        return back()->with('status', $published === []
            ? __('portal.terms.defaults_none')
            : __('portal.terms.defaults_published', ['locales' => implode(', ', $published)]));
    }

    public function update(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('portal.locales'), true), 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        $before = TermsVersion::latestFor($locale);
        $terms = TermsVersion::publish($locale, $data['body']);
        if ($terms->is($before)) {
            return back()->with('status', __('portal.terms.unchanged'));
        }
        Audit::log('terms.published', $terms, ['locale' => $locale, 'number' => $terms->number]);

        return back()->with('status', __('portal.terms.published', ['version' => $terms->number]));
    }
}
