<?php

namespace App\Http\Controllers\Admin;

use App\Approval\ApprovalSettings;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Setting;
use App\Sms\PhoneNumber;
use App\Sms\SmsSettings;
use App\Support\LocalInstance;
use App\Support\Redact;
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
        Log::info('Settings updated', ['user' => $request->user()->id, 'keys' => array_keys($data)]);

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
        Log::info('SMS settings updated', ['user' => $request->user()->id]);

        return back()->with('status', __('portal.saved'));
    }

    /** Send a test SMS synchronously with this channel (costs one SMS). */
    public function testChannel(Request $request, Channel $channel): RedirectResponse
    {
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

            return back()->withErrors(['phone' => __('portal.channels.test_failed', ['error' => $message])]);
        }
        Log::info('Test SMS sent', ['user' => $request->user()->id, 'channel' => $channel->id, 'phone' => Redact::phone($e164), 'provider_id' => $id]);

        return back()->with('status', __('portal.channels.test_sent', ['id' => $id ?? '–']));
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
        Log::info('Channel created', ['user' => $request->user()->id, 'channel' => $channel->id, 'driver' => $driver]);

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    public function updateChannel(Request $request, Channel $channel): RedirectResponse
    {
        $this->fill($request, $channel);
        Log::info('Channel updated', ['user' => $request->user()->id, 'channel' => $channel->id]);

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    public function destroyChannel(Request $request, Channel $channel): RedirectResponse
    {
        $channel->delete();
        Log::info('Channel deleted', ['user' => $request->user()->id, 'channel' => $channel->id]);

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
