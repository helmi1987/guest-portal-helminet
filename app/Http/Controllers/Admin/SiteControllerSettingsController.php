<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Nas\AuthorizesViaController;
use App\Nas\Unifi\UnifiSettings;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Controller of a UniFi site (CONCEPT.md §5.1, decision 2026-11-04, v0.27.0, experimental; admin only):
 * settings section on the site page and its check button. The API key and the local account's password
 * are encrypted with the federation data key, masked, never logged, audited or flashed back as old input;
 * an empty field keeps them, "remove" deletes them.
 */
class SiteControllerSettingsController extends Controller
{
    public function update(Request $request, Site $site): RedirectResponse
    {
        $driver = $site->driver();
        abort_unless($driver instanceof AuthorizesViaController, 404);
        $back = route('admin.sites.edit', $site).'#controller';
        $hasKey = UnifiSettings::hasSecret($site, 'api_key') && ! $request->boolean('controller.api_key_clear');
        $hasPassword = UnifiSettings::hasSecret($site, 'password') && ! $request->boolean('controller.password_clear');

        $validator = Validator::make($request->all(), $driver->controllerFields(), [], collect(['url', 'mode', 'api_key', 'username',
            'password', 'unifi_site', 'tls_pin', 'duration_default_s'])
            ->mapWithKeys(fn ($f) => ["controller.$f" => __('portal.controller.fields.'.$f)])->all());
        $validator->after(function ($v) use ($request, $hasKey, $hasPassword) {
            $input = (array) $request->input('controller', []);
            $mode = $input['mode'] ?? null;
            if ($mode === UnifiSettings::MODE_API_KEY && ! $hasKey && (string) ($input['api_key'] ?? '') === '') {
                $v->errors()->add('controller.api_key', __('portal.controller.errors.api_key_required'));
            }
            if ($mode === UnifiSettings::MODE_LEGACY) {
                if (trim((string) ($input['username'] ?? '')) === '') {
                    $v->errors()->add('controller.username', __('portal.controller.errors.username_required'));
                }
                if (! $hasPassword && (string) ($input['password'] ?? '') === '') {
                    $v->errors()->add('controller.password', __('portal.controller.errors.password_required'));
                }
            }
        });
        if ($validator->fails()) {
            return redirect()->to($back)->withErrors($validator)
                ->withInput($request->except(['controller.api_key', 'controller.password']));
        }
        $data = $validator->validated()['controller'];

        $config = [
            'url' => rtrim(strtolower(trim($data['url'])), '/'),
            'mode' => $data['mode'],
            'username' => trim((string) ($data['username'] ?? '')),
            'unifi_site' => trim($data['unifi_site']),
            'verify_tls' => $request->boolean('controller.verify_tls'),
            'tls_pin' => (string) ($data['tls_pin'] ?? ''),
            'duration_default_s' => (int) $data['duration_default_s'],
        ];
        $secret = fn (string $field) => $request->boolean("controller.{$field}_clear") ? ''
            : (($value = (string) ($data[$field] ?? '')) !== '' ? $value : null);
        $secrets = ['api_key' => $secret('api_key'), 'password' => $secret('password')];

        $site->settings = UnifiSettings::mergeInto($site, $config, $secrets);
        $site->save();
        // No key, no password: URL, mode, site and what changed.
        Audit::log('site.controller_updated', $site, ['site' => $site->slug, 'url' => $config['url'], 'mode' => $config['mode'],
            'unifi_site' => $config['unifi_site'], 'verify_tls' => $config['verify_tls'], 'pinned' => $config['tls_pin'] !== '',
            'credential_changed' => $secrets['api_key'] !== null || $secrets['password'] !== null]);

        $response = redirect()->to($back)->with('status', __('portal.saved'));
        if (! $config['verify_tls'] && $config['tls_pin'] === '') {
            $response->with('warning', __('portal.controller.verify_off_warning'));
        }

        return $response;
    }

    /** Check button: login, site lookup, version and client count; the result is flashed. */
    public function check(Site $site): RedirectResponse
    {
        $driver = $site->driver();
        abort_unless($driver instanceof AuthorizesViaController, 404);
        $result = $driver->checkController($site);
        Log::info('Site controller checked', ['site' => $site->slug, 'status' => $result['status']]);
        Audit::log('site.controller_checked', $site, ['site' => $site->slug, 'status' => $result['status']]);

        return redirect()->to(route('admin.sites.edit', $site).'#controller')->with('controller_check', $result);
    }
}
