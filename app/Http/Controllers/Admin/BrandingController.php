<?php

namespace App\Http\Controllers\Admin;

use App\Branding\BrandingAssets;
use App\Branding\BrandingException;
use App\Branding\BrandingSettings;
use App\Branding\CssPolicy;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Appearance (admin only, docs/branding.md): global logo, favicon, colours and custom CSS in the
 * settings, per-site overrides on the site page. Audited without file contents or CSS text.
 */
class BrandingController extends Controller
{
    public function __construct(private readonly BrandingAssets $assets) {}

    public function updateGlobal(Request $request): RedirectResponse
    {
        $this->validateInput($request);
        $old = BrandingSettings::global();
        $new = $old;

        foreach ([BrandingAssets::LOGO, BrandingAssets::FAVICON] as $kind) {
            if ($request->hasFile($kind)) {
                $new[$kind] = $this->store($request->file($kind), $kind);
            } elseif ($request->boolean($kind.'_remove')) {
                $new[$kind] = null;
            }
        }
        foreach (BrandingSettings::COLORS as $key) {
            $new[$key] = BrandingSettings::color($request->input($key));
        }
        $new['css'] = $this->css($request);

        BrandingSettings::saveGlobal($new);
        $this->prune();
        Audit::log('settings.branding', null, ['changed' => $this->changed($old, BrandingSettings::global())]);

        return back()->with('status', __('portal.saved'));
    }

    public function updateSite(Request $request, Site $site): RedirectResponse
    {
        $this->validateInput($request);
        $old = BrandingSettings::siteOverrides($site);
        $override = fn (string $key) => $request->boolean("override.$key");
        $new = [];

        foreach ([BrandingAssets::LOGO, BrandingAssets::FAVICON] as $kind) {
            if (! $override($kind)) {
                continue;
            }
            if ($request->hasFile($kind)) {
                $new[$kind] = $this->store($request->file($kind), $kind);
            } elseif (isset($old[$kind])) {
                $new[$kind] = $old[$kind];
            } else {
                throw ValidationException::withMessages([$kind => __('portal.branding.errors.file_required')]);
            }
        }
        foreach (BrandingSettings::COLORS as $key) {
            if ($override($key)) {
                $new[$key] = BrandingSettings::color($request->input($key))
                    ?? throw ValidationException::withMessages([$key => __('portal.branding.errors.color_required')]);
            }
        }
        if ($override('css')) {
            $new['css'] = $this->css($request);
        }

        BrandingSettings::saveSite($site, $new);
        $this->prune();
        Audit::log('site.branding', $site, ['slug' => $site->slug, 'overrides' => array_keys(BrandingSettings::siteOverrides($site)),
            'changed' => $this->changed($old, BrandingSettings::siteOverrides($site))]);

        return back()->with('status', __('portal.saved'));
    }

    private function validateInput(Request $request): void
    {
        $request->validate([
            'logo' => ['nullable', 'file', 'max:'.intdiv((int) config('portal.branding.logo_max_bytes'), 1024)],
            'favicon' => ['nullable', 'file', 'max:'.intdiv((int) config('portal.branding.favicon_max_bytes'), 1024)],
            'logo_remove' => ['boolean'],
            'favicon_remove' => ['boolean'],
            'override' => ['array'],
            'override.*' => ['boolean'],
            ...collect(BrandingSettings::COLORS)->mapWithKeys(fn ($key) => [$key => ['nullable', 'string',
                'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/']])->all(),
            'css' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (strlen((string) $value) > (int) config('portal.branding.css_max_bytes')) {
                    $fail(__('portal.branding.errors.css_too_large', ['kb' => intdiv((int) config('portal.branding.css_max_bytes'), 1024)]));
                } elseif ($error = CssPolicy::violation((string) $value)) {
                    $fail(__('portal.branding.errors.'.$error));
                }
            }],
        ]);
    }

    /** @return array{hash: string, ext: string} */
    private function store(UploadedFile $file, string $kind): array
    {
        try {
            return $this->assets->store((string) $file->getRealPath(), $kind);
        } catch (BrandingException $e) {
            throw ValidationException::withMessages([$kind => __('portal.branding.errors.'.$e->reason, [
                'kb' => intdiv((int) config("portal.branding.{$kind}_max_bytes"), 1024),
                'px' => config('portal.branding.max_pixels'),
            ])]);
        }
    }

    private function css(Request $request): string
    {
        return str_replace("\r\n", "\n", trim((string) $request->input('css', '')));
    }

    private function prune(): void
    {
        $this->assets->prune(BrandingSettings::referencedFiles());
    }

    /** @return list<string> keys whose value changed (values are not audited) */
    private function changed(array $old, array $new): array
    {
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);

        return array_values(array_filter($keys, fn ($key) => ($old[$key] ?? null) !== ($new[$key] ?? null)));
    }
}
