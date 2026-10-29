<?php

namespace App\Http\Controllers\Admin;

use App\Approval\ApprovalSettings;
use App\Auth\Oidc\OidcClient;
use App\Auth\Oidc\OidcSettings;
use App\Auth\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Setting;
use App\Sms\PhoneNumber;
use App\Sms\SmsSettings;
use App\Support\Audit;
use App\Support\LocalInstance;
use App\Support\Redact;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Settings (admin only): notification and SMS settings, channels and SMS providers (CONCEPT.md §9).
 */
class SettingsController extends Controller
{
    public function show(): View
    {
        return view('admin.settings', [
            'channels' => Channel::query()->where('kind', Channel::KIND_NOTIFICATION)->orderBy('name')->get(),
            'smsChannels' => Channel::query()->where('kind', Channel::KIND_SMS)->orderBy('name')->get(),
            'smsDurationHours' => round(SmsSettings::durationS() / 3600, 2),
            'smsCountries' => implode(', ', SmsSettings::countries()),
            'smsCaps' => SmsSettings::caps(),
            'adminUrl' => Setting::get('admin_url'),
            'locale' => ApprovalSettings::locale(),
            'telegramOwner' => ApprovalSettings::isTelegramOwner(),
            'require2fa' => TwoFactor::required(),
            'oidc' => OidcSettings::all(),
            'oidcHasSecret' => OidcSettings::hasClientSecret(),
            'oidcSecretBroken' => OidcSettings::clientSecretBroken(),
            'oidcRedirectUri' => OidcSettings::redirectUri(),
        ]);
    }

    public function update(Request $request, LocalInstance $instance): RedirectResponse
    {
        $data = $request->validate([
            'admin_url' => ['nullable', 'url:https,http', 'max:255'],
            'notification_locale' => ['required', Rule::in(config('portal.locales'))],
            'telegram_owner' => ['boolean'],
        ]);
        Setting::put('admin_url', isset($data['admin_url']) ? rtrim($data['admin_url'], '/') : null);
        Setting::put('notification_locale', $data['notification_locale']);
        Setting::put('telegram_owner', $request->boolean('telegram_owner') ? $instance->id() : 'none');
        Audit::log('settings.updated', null, ['keys' => ['admin_url', 'notification_locale', 'telegram_owner']]);

        return back()->with('status', __('portal.saved'));
    }

    /** Security: require TOTP for all admin UI users (CONCEPT.md §9). */
    public function updateSecurity(Request $request): RedirectResponse
    {
        $request->validate(['require_2fa' => ['boolean']]);
        Setting::put(TwoFactor::REQUIRED_SETTING, $request->boolean('require_2fa'));
        Audit::log('settings.security', null, ['require_2fa' => $request->boolean('require_2fa')]);

        return back()->with('status', __('portal.saved'));
    }

    /**
     * OIDC login for the admin UI (CONCEPT.md §9). An empty secret field keeps the stored secret; the
     * secret is never logged or audited.
     */
    public function updateOidc(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'oidc_enabled' => ['boolean'],
            'oidc_label' => ['required', 'string', 'max:50'],
            'oidc_issuer' => ['nullable', 'required_if_accepted:oidc_enabled', 'url:https', 'max:255'],
            'oidc_client_id' => ['nullable', 'required_if_accepted:oidc_enabled', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string', 'max:500'],
            'oidc_client_secret_clear' => ['boolean'],
            'oidc_scopes' => ['required', 'string', 'max:255', 'regex:/(^|\s)openid(\s|$)/'],
            'oidc_groups_claim' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_:.\/-]+$/'],
            'oidc_admin_group' => ['required', 'string', 'max:255'],
            'oidc_manager_group' => ['required', 'string', 'max:255', 'different:oidc_admin_group'],
            'oidc_totp_mode' => ['required', Rule::in(OidcSettings::TOTP_MODES)],
            'oidc_acr' => ['nullable', 'string', 'max:255'],
            'oidc_max_auth_age' => ['nullable', 'integer', 'between:60,2592000'],
            'oidc_link_by_email' => ['boolean'],
        ]);
        $old = OidcSettings::all();
        $config = [
            'enabled' => $request->boolean('oidc_enabled'),
            'label' => trim($data['oidc_label']),
            'issuer' => trim((string) ($data['oidc_issuer'] ?? '')),
            'client_id' => trim((string) ($data['oidc_client_id'] ?? '')),
            'scopes' => trim((string) preg_replace('/\s+/', ' ', $data['oidc_scopes'])),
            'groups_claim' => $data['oidc_groups_claim'],
            'admin_group' => trim($data['oidc_admin_group']),
            'manager_group' => trim($data['oidc_manager_group']),
            'totp_mode' => $data['oidc_totp_mode'],
            'acr' => trim((string) ($data['oidc_acr'] ?? '')),
            'max_auth_age' => isset($data['oidc_max_auth_age']) ? (int) $data['oidc_max_auth_age'] : null,
            'link_by_email' => $request->boolean('oidc_link_by_email'),
        ];
        $secret = $request->boolean('oidc_client_secret_clear') ? '' : (trim((string) ($data['oidc_client_secret'] ?? '')) ?: null);
        OidcSettings::save($config, $secret);
        OidcClient::forgetCache($old['issuer']);
        OidcClient::forgetCache($config['issuer']);
        // Audit keys must not look like secrets (Audit strips them), hence "mfa_mode" / "credential_changed".
        Audit::log('settings.oidc', null, ['enabled' => $config['enabled'], 'issuer' => $config['issuer'],
            'scopes' => $config['scopes'], 'groups_claim' => $config['groups_claim'], 'admin_group' => $config['admin_group'],
            'manager_group' => $config['manager_group'], 'mfa_mode' => $config['totp_mode'], 'acr' => $config['acr'],
            'max_auth_age' => $config['max_auth_age'], 'link_by_email' => $config['link_by_email'],
            'credential_changed' => $secret !== null]);

        return back()->with('status', __('portal.saved'));
    }

    public function updateSms(Request $request): RedirectResponse
    {
        $caps = array_keys(config('portal.sms.caps'));
        $data = $request->validate([
            'sms_duration_hours' => ['required', 'numeric', 'between:0.25,8760'],
            'sms_countries' => ['required', 'string', 'max:500'],
            ...collect($caps)->mapWithKeys(fn ($k) => ["sms_caps.$k" => ['required', 'integer', 'between:0,100000']])->all(),
        ]);
        $countries = array_values(array_unique(array_filter(array_map(fn ($c) => strtoupper(trim($c)),
            preg_split('/[\s,;]+/', $data['sms_countries'])))));
        if ($countries === [] || collect($countries)->contains(fn ($c) => ! preg_match('/^[A-Z]{2}$/', $c))) {
            throw ValidationException::withMessages(['sms_countries' => __('portal.settings.sms_countries_invalid')]);
        }
        Setting::put('sms_duration_s', (int) round($data['sms_duration_hours'] * 3600));
        Setting::put('sms_countries', $countries);
        Setting::put('sms_caps', array_map('intval', $data['sms_caps']));
        Audit::log('settings.sms', null, ['duration_s' => (int) round($data['sms_duration_hours'] * 3600),
            'countries' => $countries, 'caps' => array_map('intval', $data['sms_caps'])]);

        return back()->with('status', __('portal.saved'));
    }

    /**
     * Test button: an SMS channel sends a test SMS (costs one SMS), a notification channel posts a
     * test message. Errors are shown as HTTP status only; the full error is logged redacted.
     */
    public function testChannel(Request $request, Channel $channel): RedirectResponse
    {
        if ($channel->kind === Channel::KIND_NOTIFICATION) {
            return $this->testNotificationChannel($request, $channel);
        }
        abort_unless($channel->kind === Channel::KIND_SMS, 404);
        $phone = $request->validate(['phone' => ['required', 'string', 'max:30']])['phone'];
        [$e164, $error] = PhoneNumber::normalise($phone, SmsSettings::countries());
        if ($e164 === null) {
            throw ValidationException::withMessages(['phone' => __('portal.guest.errors.phone_'.$error,
                ['countries' => implode(', ', SmsSettings::countries())])]);
        }
        try {
            $id = $channel->smsDriver()->send($channel, $e164, __('portal.sms.test'));
        } catch (Throwable $e) {
            $message = Redact::secrets($e->getMessage(), $channel->secretValues());
            Log::warning('Test SMS failed', ['channel' => $channel->id, 'phone' => Redact::phone($e164), 'error' => $message]);
            Audit::log('channel.tested', $channel, ['result' => 'failed']);

            return back()->withErrors(['phone' => __('portal.channels.test_failed', ['error' => $message])]);
        }
        Log::info('Test SMS sent', ['user' => $request->user()->id, 'channel' => $channel->id, 'phone' => Redact::phone($e164), 'provider_id' => $id]);
        Audit::log('channel.tested', $channel, ['result' => 'ok']);

        return back()->with('status', __('portal.channels.test_sent', ['id' => $id ?? '–']));
    }

    private function testNotificationChannel(Request $request, Channel $channel): RedirectResponse
    {
        try {
            $channel->driver()->test($channel, __('portal.channels.test_message', [], ApprovalSettings::locale()));
        } catch (Throwable $e) {
            $message = Redact::secrets($e->getMessage(), $channel->secretValues());
            Log::warning('Test notification failed', ['channel' => $channel->id, 'driver' => $channel->driver, 'error' => $message]);
            Audit::log('channel.tested', $channel, ['result' => 'failed']);
            $error = match (true) {
                $e instanceof RequestException => 'HTTP '.$e->response->status(),
                (bool) preg_match('/status code (\d{3})/', $message, $m) => 'HTTP '.$m[1],
                $e instanceof ConnectionException => __('portal.channels.test_connection'),
                default => __('portal.channels.test_error'),
            };

            return back()->withErrors(['test' => __('portal.channels.test_notify_failed', ['error' => $error])]);
        }
        Log::info('Test notification sent', ['user' => $request->user()->id, 'channel' => $channel->id]);
        Audit::log('channel.tested', $channel, ['result' => 'ok']);

        return back()->with('status', __('portal.channels.test_notify_sent'));
    }

    public function createChannel(Request $request): View
    {
        $driver = $request->query('driver');
        abort_unless(Channel::kindOf($driver) !== null, 404);

        return view('admin.channel', ['channel' => new Channel(['driver' => $driver, 'enabled' => true])]);
    }

    public function editChannel(Channel $channel): View
    {
        return view('admin.channel', ['channel' => $channel]);
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $driver = (string) $request->input('driver');
        abort_unless(Channel::kindOf($driver) !== null, 404);

        $channel = new Channel(['kind' => Channel::kindOf($driver), 'driver' => $driver]);
        $this->fill($request, $channel);
        Audit::log('channel.created', $channel, ['driver' => $driver, 'name' => $channel->name]);

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    public function updateChannel(Request $request, Channel $channel): RedirectResponse
    {
        $this->fill($request, $channel);
        Audit::log('channel.updated', $channel, ['name' => $channel->name, 'enabled' => $channel->enabled]);

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    public function destroyChannel(Request $request, Channel $channel): RedirectResponse
    {
        $channel->delete();
        Audit::log('channel.deleted', $channel, ['name' => $channel->name]);

        return redirect()->route('admin.settings')->with('status', __('portal.deleted'));
    }

    /** Validate and save; empty secret fields keep the stored value. */
    private function fill(Request $request, Channel $channel): void
    {
        $fields = Channel::fieldsOf($channel->driver);
        $old = $channel->exists ? ($channel->config ?? []) : [];
        $rules = ['name' => ['required', 'string', 'max:100'], 'enabled' => ['boolean']];
        foreach ($fields as $field => $spec) {
            $required = ($spec['required'] ?? false) && ! (($spec['secret'] ?? false) && ! empty($old[$field]));
            $rules["config.$field"] = [$required ? 'required' : 'nullable', 'string', 'max:500',
                ...(in_array($field, ['url', 'api_url'], true) ? ['url:https,http'] : [])];
        }
        $data = $request->validate($rules);

        $config = [];
        foreach ($fields as $field => $spec) {
            $value = trim((string) ($data['config'][$field] ?? ''));
            $config[$field] = ($spec['secret'] ?? false) && $value === '' ? ($old[$field] ?? null) : $value;
        }
        $channel->fill(['name' => $data['name'], 'enabled' => $request->boolean('enabled'), 'config' => $config])->save();
    }
}
