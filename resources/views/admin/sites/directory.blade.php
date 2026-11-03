{{-- Site page → account login with the site's Active Directory / LDAP (CONCEPT.md §7.2 E, decision 2026-11-03).
     The service account password is never rendered; an empty field keeps it. --}}
@php
    $dir = \App\Auth\Directory\DirectorySettings::forSite($site);
    $dirHasPassword = \App\Auth\Directory\DirectorySettings::hasPassword($site);
    $dirBroken = \App\Auth\Directory\DirectorySettings::passwordBroken($site);
    $dirReady = \App\Auth\Directory\DirectorySettings::isConfigured($site);
    $dirSwitch = \App\Auth\Social\LoginMethods::enabled(\App\Auth\Social\LoginMethods::ACCOUNT);
    $dirCheck = session('directory_check');
    $old = fn (string $field, mixed $value) => old('directory.'.$field, $value);
@endphp
<form method="POST" action="{{ route('admin.sites.directory', $site) }}" id="directory" class="card mb-6 grid gap-4 md:grid-cols-2" data-site-directory>
    @csrf @method('PUT')
    <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.directory.title') }}</h2>
    <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.directory.help') }}</p>

    @if (is_array($dirCheck))
        <p role="status" data-directory-check="{{ $dirCheck['status'] }}" @class(['rounded px-4 py-2 text-sm md:col-span-2',
            'bg-green-100 text-green-800' => $dirCheck['status'] === 'ok',
            'bg-red-100 text-red-800' => $dirCheck['status'] !== 'ok'])>{{ $dirCheck['message'] }}</p>
    @endif

    <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs md:col-span-2" data-directory-status>
        @if ($dirReady)
            <span class="text-green-700" data-directory-ready>{{ __('portal.directory.ready') }}</span>
            @unless ($dirSwitch)
                <span class="text-amber-700" data-directory-switch-off>{{ __('portal.directory.switch_off') }}</span>
            @endunless
        @else
            <span class="text-slate-600">{{ __('portal.directory.not_ready') }}</span>
        @endif
        @if ($dirBroken)
            <span class="text-red-700">{{ __('portal.directory.password_broken') }}</span>
        @endif
    </p>

    <label class="flex items-start gap-2 text-sm md:col-span-2">
        <input type="hidden" name="directory[enabled]" value="0">
        <input type="checkbox" name="directory[enabled]" value="1" @checked($old('enabled', $dir['enabled'])) class="mt-0.5">
        <span>{{ __('portal.directory.enabled') }}</span>
    </label>
    <label class="label">{{ __('portal.directory.fields.host') }}
        <input name="directory[host]" value="{{ $old('host', $dir['host']) }}" placeholder="dc1.example.com" autocomplete="off" class="field">
        @error('directory.host')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.directory.fields.port') }}
        <input name="directory[port]" type="number" min="1" max="65535" value="{{ $old('port', $dir['port']) }}" required class="field">
        <span class="text-xs text-slate-500">{{ __('portal.directory.port_help') }}</span>
        @error('directory.port')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.directory.fields.tls') }}
        <select name="directory[tls]" class="field">
            @foreach (\App\Auth\Directory\DirectorySettings::TLS_MODES as $mode)
                <option value="{{ $mode }}" @selected($old('tls', $dir['tls']) === $mode)>{{ __('portal.directory.tls_modes.'.$mode) }}</option>
            @endforeach
        </select>
        <span class="text-xs text-amber-700">{{ __('portal.directory.tls_help') }}</span></label>
    <div class="flex flex-col gap-2 text-sm">
        <label class="flex items-start gap-2">
            <input type="hidden" name="directory[verify]" value="0">
            <input type="checkbox" name="directory[verify]" value="1" @checked($old('verify', $dir['verify'])) class="mt-0.5">
            <span>{{ __('portal.directory.verify') }}</span>
        </label>
        <span class="text-xs text-slate-500">{{ __('portal.directory.verify_help') }}</span>
    </div>
    <label class="label md:col-span-2">{{ __('portal.directory.fields.ca_cert') }}
        <textarea name="directory[ca_cert]" rows="3" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----" class="field font-mono text-xs">{{ $old('ca_cert', $dir['ca_cert']) }}</textarea>
        <span class="text-xs text-slate-500">{{ __('portal.directory.ca_cert_help') }}</span>
        @error('directory.ca_cert')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.directory.fields.base_dn') }}
        <input name="directory[base_dn]" value="{{ $old('base_dn', $dir['base_dn']) }}" placeholder="DC=example,DC=com" autocomplete="off" class="field">
        @error('directory.base_dn')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.directory.fields.bind_dn') }}
        <input name="directory[bind_dn]" value="{{ $old('bind_dn', $dir['bind_dn']) }}" placeholder="CN=svc-portal,OU=Service,DC=example,DC=com" autocomplete="off" class="field">
        <span class="text-xs text-slate-500">{{ __('portal.directory.bind_dn_help') }}</span>
        @error('directory.bind_dn')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.directory.fields.bind_password') }}
        <input type="password" name="directory[bind_password]" value="" autocomplete="new-password" maxlength="256"
            placeholder="{{ $dirHasPassword ? __('portal.channels.secret_keep') : '' }}" class="field">
        @error('directory.bind_password')<span class="text-xs text-red-700">{{ $message }}</span>@enderror
        @if ($dirHasPassword)
            <span class="text-xs text-slate-500" data-directory-password-stored>{{ __('portal.directory.password_stored') }}</span>
            <span class="flex items-center gap-2 text-xs text-slate-500">
                <input type="hidden" name="directory[bind_password_clear]" value="0">
                <input type="checkbox" name="directory[bind_password_clear]" value="1"> {{ __('portal.directory.password_clear') }}</span>
        @endif
    </label>
    <label class="label md:col-span-2">{{ __('portal.directory.fields.user_filter') }}
        <input name="directory[user_filter]" value="{{ $old('user_filter', $dir['user_filter']) }}" required class="field font-mono text-sm">
        <span class="text-xs text-slate-500">{{ __('portal.directory.user_filter_help') }}</span>
        @error('directory.user_filter')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label md:col-span-2">{{ __('portal.directory.fields.group_dn') }}
        <input name="directory[group_dn]" value="{{ $old('group_dn', $dir['group_dn']) }}" placeholder="CN=Guest-WiFi,OU=Groups,DC=example,DC=com" autocomplete="off" class="field">
        <span class="text-xs text-slate-500">{{ __('portal.directory.group_dn_help') }}</span>
        @error('directory.group_dn')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.directory.fields.name_attributes') }}
        <input name="directory[name_attributes]" value="{{ $old('name_attributes', implode(', ', $dir['name_attributes'])) }}" class="field">
        <span class="text-xs text-slate-500">{{ __('portal.directory.name_attributes_help') }}</span>
        @error('directory.name_attributes')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.directory.fields.email_attribute') }}
        <input name="directory[email_attribute]" value="{{ $old('email_attribute', $dir['email_attribute']) }}" class="field">
        <span class="text-xs text-slate-500">{{ __('portal.directory.email_attribute_help') }}</span>
        @error('directory.email_attribute')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.directory.fields.timeout_s') }}
        <input name="directory[timeout_s]" type="number" min="1" max="30" value="{{ $old('timeout_s', $dir['timeout_s']) }}" required class="field">
        @error('directory.timeout_s')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
    <label class="label">{{ __('portal.directory.fields.duration_hours') }}
        <input name="directory[duration_hours]" type="number" step="any" min="0.0833" max="720"
            value="{{ $old('duration_hours', $dir['duration_s'] !== null ? round($dir['duration_s'] / 3600, 2) : '') }}"
            placeholder="{{ round(\App\Auth\Directory\DirectorySettings::durationS() / 3600, 2) }}" class="field">
        <span class="text-xs text-slate-500">{{ __('portal.directory.duration_help') }}</span>
        @error('directory.duration_hours')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>

    <div class="flex flex-wrap items-center gap-2 md:col-span-2">
        <button class="btn">{{ __('portal.save') }}</button>
        <button type="submit" form="directory-check" class="btn-light">{{ __('portal.directory.check_button') }}</button>
        <span class="text-xs text-slate-500">{{ __('portal.directory.check_help') }}</span>
    </div>
</form>
<form method="POST" action="{{ route('admin.sites.directory.check', $site) }}" id="directory-check" class="hidden">@csrf</form>
