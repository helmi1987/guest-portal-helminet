<?php

namespace App\Http\Controllers\Admin;

use App\Branding\BrandingSettings;
use App\Guest\GuestPreview;
use App\Guest\GuestTexts;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Guest texts per language with a live preview (admin only, CONCEPT.md §9 "Guest texts"). The preview
 * renders the real guest views in an iframe; the admin page posts the unsaved drafts to it.
 */
class GuestTextsController extends Controller
{
    public function index(Request $request): View
    {
        $locale = $this->locale($request->query('locale'));
        $screen = in_array($request->query('screen'), GuestPreview::screens(), true) ? $request->query('screen') : 'chooser';

        return view('admin.texts', [
            'locale' => $locale,
            'screen' => $screen,
            'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
            'site' => $this->site($request->query('site')),
            'overrides' => GuestTexts::overrides($locale),
        ]);
    }

    public function update(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('portal.locales'), true), 404);
        $request->validate(['texts' => ['sometimes', 'array']]);
        $input = (array) $request->input('texts', []);

        $texts = [];
        $errors = [];
        foreach (GuestTexts::keys() as $key) {
            $field = GuestTexts::field($key);
            $text = GuestTexts::normalize($input[$field] ?? '');
            $label = self::label($key);
            if (mb_strlen($text) > GuestTexts::MAX) {
                $errors["texts.$field"] = __('validation.max.string', ['attribute' => $label, 'max' => GuestTexts::MAX]);
            } elseif ($text !== '' && ($missing = GuestTexts::missingPlaceholders($key, $locale, $text)) !== []) {
                $errors["texts.$field"] = __('portal.texts.missing_placeholder', ['field' => $label, 'placeholder' => ':'.$missing[0]]);
            }
            $texts[$key] = $text;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changed = GuestTexts::save($locale, $texts);
        if ($changed !== []) {
            Audit::log('settings.guest_texts', null, ['locale' => $locale, 'keys' => $changed]);
        }

        return redirect()->route('admin.texts', array_filter(['locale' => $locale, 'site' => $request->input('site'),
            'screen' => $request->input('screen')]))
            ->with('status', __('portal.texts.saved', ['language' => __('portal.languages.'.$locale)]));
    }

    /** Preview with the stored texts (iframe src, links inside the preview without JS). */
    public function preview(Request $request): Response
    {
        $locale = $this->locale($request->query('locale'));

        return $this->render($request, $locale, GuestTexts::overrides($locale));
    }

    /**
     * Preview with the drafts of the admin form (`texts[<field>]`, empty = default), never stored. Without
     * `texts` (a form inside the preview, posted without JS) the stored texts.
     */
    public function previewDraft(Request $request): Response
    {
        $locale = $this->locale($request->input('locale'));
        if (! is_array($input = $request->input('texts'))) {
            return $this->render($request, $locale, GuestTexts::overrides($locale));
        }
        $texts = [];
        foreach (GuestTexts::keys() as $key) {
            $text = GuestTexts::normalize($input[GuestTexts::field($key)] ?? '');
            if ($text !== '') {
                $texts[$key] = mb_substr($text, 0, GuestTexts::MAX);
            }
        }

        return $this->render($request, $locale, $texts);
    }

    /** Human label of a key for the form and the validation messages. */
    public static function label(string $key): string
    {
        if (preg_match('/^guest\.error_pages\.([a-z_]+)\.(title|text)$/', $key, $m)) {
            return __('portal.texts.error_label', ['kind' => __('portal.texts.error_kinds.'.$m[1]), 'part' => __('portal.texts.parts.'.$m[2])]);
        }

        return __('portal.texts.labels.'.substr($key, 6));
    }

    /** @param  array<string, string>  $texts  key => text applied while the view renders */
    private function render(Request $request, string $locale, array $texts): Response
    {
        $screen = $request->input('screen');
        $preview = new GuestPreview($locale, in_array($screen, GuestPreview::screens(), true) ? $screen : 'chooser',
            $this->site($request->input('site')));

        // The guest views read the site of the branding from the request (no portal session here).
        $request->attributes->set(BrandingSettings::SITE_ATTRIBUTE, $preview->site);
        $adminLocale = app()->getLocale();
        app()->setLocale($locale);
        ViewFactory::share('guestPreview', $preview);
        GuestTexts::apply($locale, $texts);
        try {
            $html = $preview->view()->render();
        } finally {
            GuestTexts::restore();
            ViewFactory::share('guestPreview', null);
            app()->setLocale($adminLocale);
        }

        // Framed by the admin page only: nginx sends frame-ancestors 'self' / SAMEORIGIN for this path.
        return response($html, 200, ['Cache-Control' => 'no-store']);
    }

    private function locale(mixed $locale): string
    {
        return in_array($locale, config('portal.locales'), true) ? $locale : app()->getLocale();
    }

    /** Site of the preview branding: the selected one, else the first by name (null: global branding). */
    private function site(mixed $id): ?Site
    {
        return (is_string($id) ? Site::find($id) : null) ?? Site::query()->orderBy('name')->first();
    }
}
