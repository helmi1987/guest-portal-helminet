<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Nas\Generic\GenericSettings;
use App\Nas\Generic\Presets;
use App\Nas\GenericRadiusDriver;
use App\Nas\RedirectSignals;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Mapping of a generic RADIUS captive-portal site (CONCEPT.md §5.1, decision 2026-11-04, v0.28.0,
 * experimental; admin only): settings section on the site page, "Apply preset" (prefills the form, the admin
 * saves) and "Simulate redirect" (parses a sample redirect, no side effects). The handoff secret is encrypted,
 * masked, never logged, audited or flashed back.
 */
class SiteGenericSettingsController extends Controller
{
    public function update(Request $request, Site $site): RedirectResponse
    {
        $driver = $this->driver($site);
        $back = route('admin.sites.edit', $site).'#generic';
        $validator = Validator::make($request->all(), GenericSettings::rules(), [], collect(array_keys(GenericSettings::rules()))
            ->mapWithKeys(fn ($key) => [$key => __('portal.generic.fields.'.substr($key, 8))])->all());
        $validator->after(function ($v) use ($request) {
            $input = (array) $request->input('generic', []);
            $loginUrl = trim((string) ($input['login_url'] ?? ''));
            if (trim((string) ($input['param_login_url'] ?? '')) === '' && $loginUrl === '') {
                $v->errors()->add('generic.login_url', __('portal.generic.errors.login_url_required'));
            }
            if (str_starts_with(strtolower($loginUrl), 'http://') && ! filter_var($input['allow_http'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $v->errors()->add('generic.login_url', __('portal.generic.errors.http_needs_switch'));
            }
            $lines = array_filter(array_map('trim', preg_split('/\R/', (string) ($input['extra_fields'] ?? '')) ?: []));
            if (count($lines) > GenericSettings::MAX_EXTRA_FIELDS) {
                $v->errors()->add('generic.extra_fields', __('portal.generic.errors.extra_too_many', ['max' => GenericSettings::MAX_EXTRA_FIELDS]));
            }
            foreach ($lines as $line) {
                if (preg_match('/^[A-Za-z0-9_.-]{1,40}\s*=\s*[\x20-\x7E]{0,255}$/', $line) !== 1) {
                    $v->errors()->add('generic.extra_fields', __('portal.generic.errors.extra_invalid', ['line' => mb_substr($line, 0, 60)]));
                    break;
                }
            }
        });
        if ($validator->fails()) {
            return redirect()->to($back)->withErrors($validator)->withInput($request->except(['generic.handoff_secret']));
        }
        $data = $validator->validated()['generic'];
        $config = [
            'preset' => $data['preset'],
            'param_mac' => $data['param_mac'],
            'param_ip' => (string) ($data['param_ip'] ?? ''),
            'param_login_url' => (string) ($data['param_login_url'] ?? ''),
            'login_url' => trim((string) ($data['login_url'] ?? '')),
            'param_orig' => (string) ($data['param_orig'] ?? ''),
            'param_result' => (string) ($data['param_result'] ?? ''),
            'method' => $data['method'],
            'field_username' => $data['field_username'],
            'field_password' => $data['field_password'],
            'field_success' => (string) ($data['field_success'] ?? ''),
            'extra_fields' => implode("\n", array_map(fn ($k, $v) => $k.'='.$v,
                array_keys($extra = GenericSettings::extraFields((string) ($data['extra_fields'] ?? ''))), $extra)),
            'password_encoding' => $data['password_encoding'],
            'allow_http' => $request->boolean('generic.allow_http'),
            'wispr' => $request->boolean('generic.wispr'),
            'disconnect_attrs' => array_values(array_intersect(GenericSettings::DISCONNECT_ATTRIBUTES, (array) ($data['disconnect_attrs'] ?? []))),
            'login_page_template' => (string) ($data['login_page_template'] ?? ''),
        ];
        $secret = $request->boolean('generic.handoff_secret_clear') ? ''
            : ((($value = (string) ($data['handoff_secret'] ?? '')) !== '') ? $value : null);

        $site->settings = GenericSettings::mergeInto($site, $config, $secret);
        $site->save();
        // The mapping is not secret; the handoff secret never goes into the audit.
        Audit::log('site.generic_updated', $site, ['site' => $site->slug, 'preset' => $config['preset'], 'method' => $config['method'],
            'login_url' => $config['login_url'], 'param_login_url' => $config['param_login_url'],
            'credential_changed' => $secret !== null]);

        $response = redirect()->to($back)->with('status', __('portal.saved'));
        if (str_starts_with(strtolower($config['login_url']), 'http://')) {
            $response->with('warning', __('portal.generic.http_warning'));
        }

        return $response;
    }

    /** Prefill the form with a preset (nothing is stored; the admin checks and saves). */
    public function preset(Request $request, Site $site): RedirectResponse
    {
        $this->driver($site);
        $preset = $request->input('preset');
        abort_unless(is_string($preset) && in_array($preset, Presets::keys(), true), 422);
        $current = GenericSettings::forSite($site);
        $values = ['preset' => $preset] + Presets::PRESETS[$preset]
            + ['wispr' => $current['wispr'], 'disconnect_attrs' => Presets::DISCONNECT[$preset] ?? GenericSettings::DEFAULT_DISCONNECT];
        // Checkbox inputs as the form posts them.
        $values['allow_http'] = $values['allow_http'] ? '1' : '0';
        $values['wispr'] = $values['wispr'] ? '1' : '0';

        return redirect()->to(route('admin.sites.edit', $site).'#generic')->withInput(['generic' => $values])
            ->with('status', __('portal.generic.preset_loaded', ['preset' => __('portal.generic.presets.'.$preset.'.label')]));
    }

    /** Sample redirect after the saved mapping: what the portal would read and hand off. No side effects. */
    public function simulate(Site $site): View
    {
        $driver = $this->driver($site);
        $query = $driver->sampleQuery($site);
        $request = Request::create('/portal', 'GET', ['site' => $site->slug] + $query);
        $context = $driver->parseRedirect($request, $site);
        $signals = RedirectSignals::of($driver, $request, $site);
        $handoff = $context->loginUrl !== null
            ? $driver->loginHandoff($context, '<one-time user name>', '<one-time password>', '<status page URL>') : null;
        // Never show the handoff secret: fields built from it are masked.
        foreach (GenericSettings::extraFields(GenericSettings::forSite($site)['extra_fields']) as $name => $template) {
            if ($handoff !== null && isset($handoff['fields'][$name]) && str_contains($template, '{'.GenericSettings::SECRET.'}')) {
                $handoff['fields'][$name] = '<'.GenericSettings::SECRET.'>';
            }
        }
        $base = rtrim((string) ($site->setting('portal_url') ?: config('app.url')), '/');

        return view('admin.sites.generic-simulate', [
            'site' => $site,
            'url' => $base.'/portal/g/'.rawurlencode($site->slug).'?'.http_build_query($query),
            'query' => $query,
            'context' => $context,
            'complete' => $context->isComplete(),
            'misconfigured' => $signals['has_login_url'] && $context->loginUrl === null,
            'handoff' => $handoff,
        ]);
    }

    private function driver(Site $site): GenericRadiusDriver
    {
        $driver = $site->driver();
        abort_unless($driver instanceof GenericRadiusDriver, 404);

        return $driver;
    }
}
