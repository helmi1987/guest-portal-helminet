<x-layouts.admin :title="__('portal.admin.settings')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.admin.settings') }}</h1>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf
        <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.settings.notifications') }}</h2>
        <label class="label">{{ __('portal.settings.admin_url') }}
            <input name="admin_url" value="{{ old('admin_url', $adminUrl) }}" placeholder="https://portal.example.com:8443" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.settings.admin_url_help') }}</span></label>
        <label class="label">{{ __('portal.settings.notification_locale') }}
            <select name="notification_locale" class="field">
                @foreach (config('portal.locales') as $code)
                    <option value="{{ $code }}" @selected(old('notification_locale', $locale) === $code)>{{ __('portal.languages.'.$code) }}</option>
                @endforeach
            </select></label>
        <label class="flex items-start gap-2 text-sm md:col-span-2">
            <input type="hidden" name="telegram_owner" value="0">
            <input type="checkbox" name="telegram_owner" value="1" @checked($telegramOwner) class="mt-0.5">
            <span>{{ __('portal.settings.telegram_owner') }}<br><span class="text-xs text-slate-500">{{ __('portal.settings.telegram_owner_help') }}</span></span>
        </label>
        <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
    </form>

    <form method="POST" action="{{ route('admin.settings.sms') }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf
        <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.settings.sms') }}</h2>
        <label class="label">{{ __('portal.settings.sms_duration') }}
            <input name="sms_duration_hours" type="number" step="0.25" min="0.25" value="{{ old('sms_duration_hours', $smsDurationHours) }}" required class="field"></label>
        <label class="label">{{ __('portal.settings.sms_countries') }}
            <input name="sms_countries" value="{{ old('sms_countries', $smsCountries) }}" required class="field">
            <span class="text-xs text-slate-500">{{ __('portal.settings.sms_countries_help') }}</span></label>
        @foreach ($smsCaps as $key => $value)
            <label class="label">{{ __('portal.settings.sms_caps.'.$key) }}
                <input name="sms_caps[{{ $key }}]" type="number" min="0" value="{{ old('sms_caps.'.$key, $value) }}" required class="field"></label>
        @endforeach
        <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.settings.sms_help') }}</p>
        <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
    </form>

    <section class="card mb-6">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">{{ __('portal.channels.title') }}</h2>
            <div class="flex gap-2">
                @foreach (array_keys(\App\Approval\Channels\NotificationChannels::DRIVERS) as $driver)
                    <a href="{{ route('admin.channels.create', ['driver' => $driver]) }}" class="btn-light">+ {{ __('portal.channels.drivers.'.$driver) }}</a>
                @endforeach
            </div>
        </div>
        <table class="data-table">
            <thead><tr><th>{{ __('portal.sites.name') }}</th><th>{{ __('portal.channels.driver') }}</th><th>{{ __('portal.channels.enabled') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($channels as $channel)
                    <tr>
                        <td>{{ $channel->name }}</td>
                        <td>{{ __('portal.channels.drivers.'.$channel->driver) }}</td>
                        <td>{{ $channel->enabled ? '✓' : '–' }}</td>
                        <td><a href="{{ route('admin.channels.edit', $channel) }}" class="btn-light">{{ __('portal.channels.edit') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-slate-500">{{ __('portal.channels.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="mt-4 text-xs text-slate-500">{{ __('portal.channels.help') }}</p>
    </section>

    <section class="card">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">{{ __('portal.channels.sms_title') }}</h2>
            <div class="flex gap-2">
                @foreach (array_keys(\App\Sms\Drivers\SmsDrivers::DRIVERS) as $driver)
                    <a href="{{ route('admin.channels.create', ['driver' => $driver]) }}" class="btn-light">+ {{ __('portal.channels.drivers.'.$driver) }}</a>
                @endforeach
            </div>
        </div>
        <table class="data-table">
            <thead><tr><th>{{ __('portal.sites.name') }}</th><th>{{ __('portal.channels.driver') }}</th><th>{{ __('portal.channels.enabled') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($smsChannels as $channel)
                    <tr>
                        <td>{{ $channel->name }}</td>
                        <td>{{ __('portal.channels.drivers.'.$channel->driver) }}</td>
                        <td>{{ $channel->enabled ? '✓' : '–' }}</td>
                        <td><a href="{{ route('admin.channels.edit', $channel) }}" class="btn-light">{{ __('portal.channels.edit') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-slate-500">{{ __('portal.channels.sms_none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="mt-4 text-xs text-slate-500">{{ __('portal.channels.sms_help') }}</p>
    </section>
</x-layouts.admin>
