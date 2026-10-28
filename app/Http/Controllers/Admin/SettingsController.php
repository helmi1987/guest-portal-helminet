<?php

namespace App\Http\Controllers\Admin;

use App\Approval\ApprovalSettings;
use App\Approval\Channels\NotificationChannels;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Setting;
use App\Support\LocalInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings (admin only): notification settings and channels (CONCEPT.md §9).
 */
class SettingsController extends Controller
{
    public function show(): View
    {
        return view('admin.settings', [
            'channels' => Channel::query()->where('kind', Channel::KIND_NOTIFICATION)->orderBy('name')->get(),
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

    public function createChannel(Request $request): View
    {
        $driver = $request->query('driver');
        abort_unless(isset(NotificationChannels::DRIVERS[$driver]), 404);

        return view('admin.channel', ['channel' => new Channel(['driver' => $driver, 'enabled' => true])]);
    }

    public function editChannel(Channel $channel): View
    {
        return view('admin.channel', ['channel' => $channel]);
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $driver = (string) $request->input('driver');
        abort_unless(isset(NotificationChannels::DRIVERS[$driver]), 404);

        $channel = new Channel(['kind' => Channel::KIND_NOTIFICATION, 'driver' => $driver]);
        $this->fill($request, $channel);
        Log::info('Notification channel created', ['user' => $request->user()->id, 'channel' => $channel->id, 'driver' => $driver]);

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    public function updateChannel(Request $request, Channel $channel): RedirectResponse
    {
        $this->fill($request, $channel);
        Log::info('Notification channel updated', ['user' => $request->user()->id, 'channel' => $channel->id]);

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    public function destroyChannel(Request $request, Channel $channel): RedirectResponse
    {
        $channel->delete();
        Log::info('Notification channel deleted', ['user' => $request->user()->id, 'channel' => $channel->id]);

        return redirect()->route('admin.settings')->with('status', __('portal.deleted'));
    }

    /** Validate and save; empty secret fields keep the stored value. */
    private function fill(Request $request, Channel $channel): void
    {
        $fields = NotificationChannels::fields($channel->driver);
        $old = $channel->exists ? ($channel->config ?? []) : [];
        $rules = ['name' => ['required', 'string', 'max:100'], 'enabled' => ['boolean']];
        foreach ($fields as $field => $spec) {
            $required = ($spec['required'] ?? false) && ! (($spec['secret'] ?? false) && ! empty($old[$field]));
            $rules["config.$field"] = [$required ? 'required' : 'nullable', 'string', 'max:500',
                ...(in_array($field, ['url'], true) ? ['url:https,http'] : [])];
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
