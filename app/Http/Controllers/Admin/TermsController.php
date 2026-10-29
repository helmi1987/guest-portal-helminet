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
    public function index(): View
    {
        $versions = TermsVersion::query()->withCount('acceptances')->orderByDesc('number')->get()->groupBy('locale');

        return view('admin.terms', ['versions' => $versions]);
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
