{{-- Site page → generic RADIUS captive portal (experimental, CONCEPT.md §5.1, decision 2026-11-04, v0.28.0).
     The handoff secret is never rendered; an empty field keeps it. "Apply preset" only prefills this form. --}}
@php
    $gen = \App\Nas\Generic\GenericSettings::forSite($site);
    $genHasSecret = \App\Nas\Generic\GenericSettings::hasSecret($site);
    $genOld = fn (string $field, mixed $value) => old('generic.'.$field, $value);
    $genPreset = $genOld('preset', $gen['preset']);
    $genAttrs = (array) $genOld('disconnect_attrs', $gen['disconnect_attrs']);
    $genBool = fn (string $field) => filter_var($genOld($field, $gen[$field]), FILTER_VALIDATE_BOOLEAN);
@endphp
<section id="generic" class="card mb-6 grid gap-4" data-site-generic>
    <h2 class="text-lg font-semibold">{{ __('portal.generic.title') }}</h2>
    <p class="rounded bg-amber-100 px-4 py-2 text-sm text-amber-900" data-generic-experimental>{{ __('portal.generic.experimental') }}</p>
    <p class="text-xs text-slate-500">{{ __('portal.generic.help') }}</p>
    <p class="text-xs text-slate-600" data-generic-limits>{{ __('portal.generic.limits') }}</p>

    <form method="POST" action="{{ route('admin.sites.generic.preset', $site) }}" class="flex flex-wrap items-end gap-3" data-generic-preset>
        @csrf
        <label class="label">{{ __('portal.generic.fields.preset') }}
            <select name="preset" class="field">
                @foreach (\App\Nas\Generic\Presets::keys() as $key)
                    <option value="{{ $key }}" @selected($genPreset === $key)>{{ __('portal.generic.presets.'.$key.'.label') }}</option>
                @endforeach
            </select></label>
        <button class="btn-light">{{ __('portal.generic.apply_preset') }}</button>
        <span class="text-xs text-slate-500">{{ __('portal.generic.preset_help') }}</span>
    </form>
    <p class="text-xs text-slate-600" data-generic-preset-note>{{ __('portal.generic.presets.'.$genPreset.'.note') }}</p>

    <form method="POST" action="{{ route('admin.sites.generic', $site) }}" class="grid gap-4 md:grid-cols-2" data-generic-form>
        @csrf @method('PUT')
        <input type="hidden" name="generic[preset]" value="{{ $genPreset }}">

        <h3 class="font-semibold md:col-span-2">{{ __('portal.generic.groups.redirect') }}</h3>
        @foreach (['param_mac' => 'mac', 'param_ip' => 'ip', 'param_login_url' => '', 'param_orig' => '', 'param_result' => ''] as $field => $placeholder)
            <label class="label">{{ __('portal.generic.fields.'.$field) }}
                <input name="generic[{{ $field }}]" value="{{ $genOld($field, $gen[$field]) }}" placeholder="{{ $placeholder }}" maxlength="40" class="field font-mono text-sm">
                @if ($field === 'param_login_url')<span class="text-xs text-slate-500">{{ __('portal.generic.param_login_url_help') }}</span>@endif
                @if ($field === 'param_result')<span class="text-xs text-slate-500">{{ __('portal.generic.param_result_help') }}</span>@endif
                @error('generic.'.$field)<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
        @endforeach
        <label class="label md:col-span-2">{{ __('portal.generic.fields.login_url') }}
            <input name="generic[login_url]" value="{{ $genOld('login_url', $gen['login_url']) }}" placeholder="https://hotspot.example.com/login" maxlength="255" class="field font-mono text-sm">
            <span class="text-xs text-slate-500">{{ __('portal.generic.login_url_help') }}</span>
            @error('generic.login_url')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>

        <h3 class="font-semibold md:col-span-2">{{ __('portal.generic.groups.handoff') }}</h3>
        <label class="label">{{ __('portal.generic.fields.method') }}
            <select name="generic[method]" class="field">
                @foreach (\App\Nas\Generic\GenericSettings::METHODS as $method)
                    <option value="{{ $method }}" @selected($genOld('method', $gen['method']) === $method)>{{ __('portal.generic.methods.'.$method) }}</option>
                @endforeach
            </select>
            <span class="text-xs text-slate-500">{{ __('portal.generic.method_help') }}</span></label>
        <label class="label">{{ __('portal.generic.fields.password_encoding') }}
            <select name="generic[password_encoding]" class="field">
                @foreach (\App\Nas\Generic\GenericSettings::ENCODINGS as $encoding)
                    <option value="{{ $encoding }}" @selected($genOld('password_encoding', $gen['password_encoding']) === $encoding)>{{ __('portal.generic.encodings.'.$encoding) }}</option>
                @endforeach
            </select></label>
        @foreach (['field_username', 'field_password', 'field_success'] as $field)
            <label class="label">{{ __('portal.generic.fields.'.$field) }}
                <input name="generic[{{ $field }}]" value="{{ $genOld($field, $gen[$field]) }}" maxlength="40" class="field font-mono text-sm">
                @error('generic.'.$field)<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
        @endforeach
        <label class="label md:col-span-2">{{ __('portal.generic.fields.extra_fields') }}
            <textarea name="generic[extra_fields]" rows="3" class="field font-mono text-sm" placeholder="buttonClicked=4">{{ $genOld('extra_fields', $gen['extra_fields']) }}</textarea>
            <span class="text-xs text-slate-500">{{ __('portal.generic.extra_fields_help', ['max' => \App\Nas\Generic\GenericSettings::MAX_EXTRA_FIELDS]) }}</span>
            @error('generic.extra_fields')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
        <div class="flex flex-col gap-1 text-sm md:col-span-2">
            <label class="flex items-start gap-2">
                <input type="hidden" name="generic[allow_http]" value="0">
                <input type="checkbox" name="generic[allow_http]" value="1" @checked($genBool('allow_http')) class="mt-0.5">
                <span>{{ __('portal.generic.fields.allow_http') }}</span></label>
            <span class="text-xs text-amber-800">{{ __('portal.generic.allow_http_help') }}</span>
        </div>
        <label class="label md:col-span-2">{{ __('portal.generic.fields.handoff_secret') }}
            <input type="password" name="generic[handoff_secret]" value="" autocomplete="new-password" maxlength="128"
                placeholder="{{ $genHasSecret ? __('portal.channels.secret_keep') : '' }}" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.generic.handoff_secret_help') }}</span>
            @error('generic.handoff_secret')<span class="text-xs text-red-700">{{ $message }}</span>@enderror
            @if ($genHasSecret)
                <span class="text-xs text-slate-500" data-generic-secret-stored>{{ __('portal.generic.secret_stored') }}</span>
                @if (\App\Nas\Generic\GenericSettings::secretBroken($site))
                    <span class="text-xs text-red-700">{{ __('portal.generic.secret_broken') }}</span>
                @endif
                <span class="flex items-center gap-2 text-xs text-slate-500">
                    <input type="hidden" name="generic[handoff_secret_clear]" value="0">
                    <input type="checkbox" name="generic[handoff_secret_clear]" value="1"> {{ __('portal.generic.secret_clear') }}</span>
            @endif
        </label>

        <h3 class="font-semibold md:col-span-2">{{ __('portal.generic.groups.radius') }}</h3>
        <div class="flex flex-col gap-1 text-sm">
            <label class="flex items-start gap-2">
                <input type="hidden" name="generic[wispr]" value="0">
                <input type="checkbox" name="generic[wispr]" value="1" @checked($genBool('wispr')) class="mt-0.5">
                <span>{{ __('portal.generic.fields.wispr') }}</span></label>
            <span class="text-xs text-slate-500">{{ __('portal.generic.wispr_help') }}</span>
        </div>
        <fieldset class="flex flex-col gap-1 text-sm">
            <legend class="mb-1">{{ __('portal.generic.fields.disconnect_attrs') }}</legend>
            @foreach (\App\Nas\Generic\GenericSettings::DISCONNECT_ATTRIBUTES as $attr)
                <label class="flex items-center gap-2"><input type="checkbox" name="generic[disconnect_attrs][]" value="{{ $attr }}" @checked(in_array($attr, $genAttrs, true))>
                    <span class="font-mono text-xs">{{ $attr }}</span></label>
            @endforeach
            <span class="text-xs text-slate-500">{{ __('portal.generic.disconnect_help') }}</span>
            @if ($genAttrs === [])
                <span class="text-xs text-amber-800" data-generic-disconnect-none>{{ __('portal.generic.disconnect_none') }}</span>
            @endif
            @error('generic.disconnect_attrs')<span class="text-xs text-red-700">{{ $message }}</span>@enderror
        </fieldset>
        <p class="text-xs text-slate-600 md:col-span-2" data-generic-mac-auth>{{ __('portal.generic.mac_auth_note') }}</p>

        <h3 class="font-semibold md:col-span-2">{{ __('portal.generic.groups.login_page') }}</h3>
        <label class="label md:col-span-2">{{ __('portal.generic.fields.login_page_template') }}
            <input name="generic[login_page_template]" value="{{ $genOld('login_page_template', $gen['login_page_template']) }}" maxlength="500" class="field font-mono text-xs"
                placeholder="mac=$CLIENT_MAC$&amp;ip=$CLIENT_IP$">
            <span class="text-xs text-slate-500">{{ __('portal.generic.login_page_template_help') }}</span>
            @error('generic.login_page_template')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>

        <div class="flex flex-wrap items-center gap-2 md:col-span-2">
            <button class="btn">{{ __('portal.save') }}</button>
            <a href="{{ route('admin.sites.generic.simulate', $site) }}" class="btn-light" data-generic-simulate>{{ __('portal.generic.simulate') }}</a>
            <span class="text-xs text-slate-500">{{ __('portal.generic.simulate_help') }}</span>
        </div>
    </form>
</section>
