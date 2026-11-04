{{-- Site page → UniFi controller (experimental, CONCEPT.md §5.1, decision 2026-11-04). API key and password
     are never rendered; an empty field keeps them. --}}
@php
    $ctl = \App\Nas\Unifi\UnifiSettings::forSite($site);
    $ctlHasKey = \App\Nas\Unifi\UnifiSettings::hasSecret($site, 'api_key');
    $ctlHasPassword = \App\Nas\Unifi\UnifiSettings::hasSecret($site, 'password');
    $ctlBroken = \App\Nas\Unifi\UnifiSettings::secretBroken($site, 'api_key') || \App\Nas\Unifi\UnifiSettings::secretBroken($site, 'password');
    $ctlReady = \App\Nas\Unifi\UnifiSettings::isConfigured($site);
    $ctlCheck = session('controller_check');
    $old = fn (string $field, mixed $value) => old('controller.'.$field, $value);
@endphp
<form method="POST" action="{{ route('admin.sites.controller', $site) }}" id="controller" class="card mb-6 grid gap-4 md:grid-cols-2" data-site-controller>
    @csrf @method('PUT')
    <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.controller.title') }}</h2>
    <p class="rounded bg-amber-100 px-4 py-2 text-sm text-amber-900 md:col-span-2" data-controller-experimental>{{ __('portal.controller.experimental') }}</p>
    <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.controller.help') }}</p>
    <p class="text-xs text-slate-600 md:col-span-2" data-controller-limits>{{ __('portal.controller.limits') }}</p>

    @if (is_array($ctlCheck))
        <p role="status" data-controller-check="{{ $ctlCheck['status'] }}" @class(['rounded px-4 py-2 text-sm md:col-span-2',
            'bg-green-100 text-green-800' => $ctlCheck['status'] === 'ok',
            'bg-red-100 text-red-800' => $ctlCheck['status'] !== 'ok'])>{{ $ctlCheck['message'] }}</p>
    @endif

    <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs md:col-span-2" data-controller-status>
        @if ($ctlReady)
            <span class="text-green-700" data-controller-ready>{{ __('portal.controller.ready') }}</span>
        @else
            <span class="text-slate-600">{{ __('portal.controller.not_ready') }}</span>
        @endif
        @if ($ctlBroken)
            <span class="text-red-700">{{ __('portal.controller.secret_broken') }}</span>
        @endif
    </p>

    <label class="label">{{ __('portal.controller.fields.url') }}
        <input name="controller[url]" value="{{ $old('url', $ctl['url']) }}" placeholder="https://unifi.example.com" autocomplete="off" class="field">
        <span class="text-xs text-slate-500">{{ __('portal.controller.url_help') }}</span>
        @error('controller.url')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.controller.fields.mode') }}
        <select name="controller[mode]" class="field">
            @foreach (\App\Nas\Unifi\UnifiSettings::MODES as $mode)
                <option value="{{ $mode }}" @selected($old('mode', $ctl['mode']) === $mode)>{{ __('portal.controller.modes.'.$mode) }}</option>
            @endforeach
        </select>
        <span class="text-xs text-slate-500">{{ __('portal.controller.mode_help') }}</span></label>
    <label class="label">{{ __('portal.controller.fields.api_key') }}
        <input type="password" name="controller[api_key]" value="" autocomplete="new-password" maxlength="255"
            placeholder="{{ $ctlHasKey ? __('portal.channels.secret_keep') : '' }}" class="field">
        @error('controller.api_key')<span class="text-xs text-red-700">{{ $message }}</span>@enderror
        @if ($ctlHasKey)
            <span class="text-xs text-slate-500" data-controller-key-stored>{{ __('portal.controller.secret_stored') }}</span>
            <span class="flex items-center gap-2 text-xs text-slate-500">
                <input type="hidden" name="controller[api_key_clear]" value="0">
                <input type="checkbox" name="controller[api_key_clear]" value="1"> {{ __('portal.controller.secret_clear') }}</span>
        @endif
    </label>
    <label class="label">{{ __('portal.controller.fields.unifi_site') }}
        <input name="controller[unifi_site]" value="{{ $old('unifi_site', $ctl['unifi_site']) }}" required class="field">
        <span class="text-xs text-slate-500">{{ __('portal.controller.unifi_site_help') }}</span>
        @error('controller.unifi_site')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.controller.fields.username') }}
        <input name="controller[username]" value="{{ $old('username', $ctl['username']) }}" autocomplete="off" class="field">
        @error('controller.username')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.controller.fields.password') }}
        <input type="password" name="controller[password]" value="" autocomplete="new-password" maxlength="255"
            placeholder="{{ $ctlHasPassword ? __('portal.channels.secret_keep') : '' }}" class="field">
        @error('controller.password')<span class="text-xs text-red-700">{{ $message }}</span>@enderror
        @if ($ctlHasPassword)
            <span class="text-xs text-slate-500" data-controller-password-stored>{{ __('portal.controller.secret_stored') }}</span>
            <span class="flex items-center gap-2 text-xs text-slate-500">
                <input type="hidden" name="controller[password_clear]" value="0">
                <input type="checkbox" name="controller[password_clear]" value="1"> {{ __('portal.controller.secret_clear') }}</span>
        @endif
    </label>
    <div class="flex flex-col gap-2 text-sm">
        <label class="flex items-start gap-2">
            <input type="hidden" name="controller[verify_tls]" value="0">
            <input type="checkbox" name="controller[verify_tls]" value="1" @checked($old('verify_tls', $ctl['verify_tls'])) class="mt-0.5">
            <span>{{ __('portal.controller.verify_tls') }}</span>
        </label>
        <span class="text-xs text-slate-500">{{ __('portal.controller.verify_tls_help') }}</span>
    </div>
    <label class="label">{{ __('portal.controller.fields.tls_pin') }}
        <input name="controller[tls_pin]" value="{{ $old('tls_pin', $ctl['tls_pin']) }}" placeholder="sha256//..." class="field font-mono text-xs">
        <span class="text-xs text-slate-500">{{ __('portal.controller.tls_pin_help') }}</span>
        @error('controller.tls_pin')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.controller.fields.duration_default_s') }}
        <input name="controller[duration_default_s]" type="number" min="60" max="2592000" value="{{ $old('duration_default_s', $ctl['duration_default_s']) }}" required class="field">
        <span class="text-xs text-slate-500">{{ __('portal.controller.duration_help') }}</span>
        @error('controller.duration_default_s')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>

    <div class="flex flex-wrap items-center gap-2 md:col-span-2">
        <button class="btn">{{ __('portal.save') }}</button>
        <button type="submit" form="controller-check" class="btn-light">{{ __('portal.controller.check_button') }}</button>
        <span class="text-xs text-slate-500">{{ __('portal.controller.check_help') }}</span>
    </div>
</form>
<form method="POST" action="{{ route('admin.sites.controller.check', $site) }}" id="controller-check" class="hidden">@csrf</form>
