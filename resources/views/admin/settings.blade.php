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

    <form method="POST" action="{{ route('admin.settings.security') }}" class="card mb-6 grid gap-4">
        @csrf
        <h2 class="text-lg font-semibold">{{ __('portal.settings.security') }}</h2>
        <label class="flex items-start gap-2 text-sm">
            <input type="hidden" name="require_2fa" value="0">
            <input type="checkbox" name="require_2fa" value="1" @checked($require2fa) class="mt-0.5">
            <span>{{ __('portal.settings.require_2fa') }}<br><span class="text-xs text-slate-500">{{ __('portal.settings.require_2fa_help') }}</span></span>
        </label>
        <div><button class="btn">{{ __('portal.save') }}</button></div>
    </form>

    <form method="POST" action="{{ route('admin.settings.oidc') }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf
        <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.oidc.title') }}</h2>
        <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.oidc.help') }}</p>
        <label class="flex items-start gap-2 text-sm md:col-span-2">
            <input type="hidden" name="oidc_enabled" value="0">
            <input type="checkbox" name="oidc_enabled" value="1" @checked(old('oidc_enabled', $oidc['enabled'])) class="mt-0.5">
            <span>{{ __('portal.oidc.enabled') }}</span>
        </label>
        <div class="md:col-span-2">
            <x-admin.code :title="__('portal.oidc.redirect_uri')" :code="$oidcRedirectUri" />
            @unless (str_starts_with($oidcRedirectUri, 'https://'))
                <p class="text-xs text-red-700">{{ __('portal.oidc.redirect_uri_https') }}</p>
            @endunless
            <span class="text-xs text-slate-500">{{ __('portal.oidc.redirect_uri_help') }}</span>
        </div>
        <label class="label">{{ __('portal.oidc.label') }}
            <input name="oidc_label" value="{{ old('oidc_label', $oidc['label']) }}" maxlength="50" required class="field"></label>
        <label class="label">{{ __('portal.oidc.issuer') }}
            <input name="oidc_issuer" value="{{ old('oidc_issuer', $oidc['issuer']) }}" placeholder="https://idp.example.com/application/o/portal/" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.oidc.issuer_help') }}</span></label>
        <label class="label">{{ __('portal.oidc.client_id') }}
            <input name="oidc_client_id" value="{{ old('oidc_client_id', $oidc['client_id']) }}" autocomplete="off" class="field"></label>
        <label class="label">{{ __('portal.oidc.client_secret') }}
            <input type="password" name="oidc_client_secret" value="" autocomplete="new-password"
                   placeholder="{{ $oidcHasSecret ? __('portal.channels.secret_keep') : '' }}" class="field">
            @if ($oidcSecretBroken)
                <span class="text-xs text-red-700">{{ __('portal.oidc.client_secret_broken') }}</span>
            @endif
            @if ($oidcHasSecret)
                <span class="flex items-center gap-2 text-xs text-slate-500">
                    <input type="hidden" name="oidc_client_secret_clear" value="0">
                    <input type="checkbox" name="oidc_client_secret_clear" value="1"> {{ __('portal.oidc.client_secret_clear') }}</span>
            @endif
        </label>
        <label class="label">{{ __('portal.oidc.scopes') }}
            <input name="oidc_scopes" value="{{ old('oidc_scopes', $oidc['scopes']) }}" required class="field"></label>
        <label class="label">{{ __('portal.oidc.groups_claim') }}
            <input name="oidc_groups_claim" value="{{ old('oidc_groups_claim', $oidc['groups_claim']) }}" required class="field"></label>
        <label class="label">{{ __('portal.oidc.admin_group') }}
            <input name="oidc_admin_group" value="{{ old('oidc_admin_group', $oidc['admin_group']) }}" required class="field"></label>
        <label class="label">{{ __('portal.oidc.manager_group') }}
            <input name="oidc_manager_group" value="{{ old('oidc_manager_group', $oidc['manager_group']) }}" required class="field"></label>
        <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.oidc.groups_help') }}</p>
        <label class="flex items-start gap-2 text-sm md:col-span-2">
            <input type="hidden" name="oidc_link_by_email" value="0">
            <input type="checkbox" name="oidc_link_by_email" value="1" @checked(old('oidc_link_by_email', $oidc['link_by_email'])) class="mt-0.5">
            <span>{{ __('portal.oidc.link_by_email') }}<br><span class="text-xs text-slate-500">{{ __('portal.oidc.link_by_email_help') }}</span></span>
        </label>
        <label class="label">{{ __('portal.oidc.totp_mode') }}
            <select name="oidc_totp_mode" class="field">
                @foreach (\App\Auth\Oidc\OidcSettings::TOTP_MODES as $mode)
                    <option value="{{ $mode }}" @selected(old('oidc_totp_mode', $oidc['totp_mode']) === $mode)>{{ __('portal.oidc.totp_modes.'.$mode) }}</option>
                @endforeach
            </select></label>
        <label class="label">{{ __('portal.oidc.acr') }}
            <input name="oidc_acr" value="{{ old('oidc_acr', $oidc['acr']) }}" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.oidc.acr_help') }}</span></label>
        <label class="label">{{ __('portal.oidc.max_auth_age') }}
            <input name="oidc_max_auth_age" type="number" min="60" max="2592000" value="{{ old('oidc_max_auth_age', $oidc['max_auth_age']) }}" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.oidc.max_auth_age_help') }}</span></label>
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
