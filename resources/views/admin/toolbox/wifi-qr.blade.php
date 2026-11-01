{{-- Wi-Fi QR code (toolbox): the password is never echoed back (no value, not flashed). --}}
<x-layouts.admin :title="__('portal.toolbox.wifi_qr.title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.toolbox.wifi_qr.title') }}</h1>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.toolbox.wifi_qr.help') }}</p>

    @if ($errors->any())
        <ul class="mb-4 rounded bg-red-100 px-4 py-2 text-sm text-red-800">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif

    {{-- Print sheet in a new tab: this form keeps its values (nothing is stored) --}}
    <form method="POST" action="{{ route('admin.toolbox.wifi-qr.print') }}" target="_blank" class="card grid gap-4 md:grid-cols-2">
        @csrf
        <label class="label">{{ __('portal.toolbox.wifi_qr.ssid') }}
            <input name="ssid" value="{{ old('ssid') }}" required maxlength="32" autocomplete="off" class="field"></label>
        <label class="label">{{ __('portal.toolbox.wifi_qr.encryption') }}
            <select name="encryption" class="field">
                <option value="WPA" @selected(old('encryption', 'WPA') === 'WPA')>{{ __('portal.toolbox.wifi_qr.encryption_wpa') }}</option>
                <option value="nopass" @selected(old('encryption') === 'nopass')>{{ __('portal.toolbox.wifi_qr.encryption_nopass') }}</option>
            </select></label>
        <label class="label md:col-span-2">{{ __('portal.toolbox.wifi_qr.password') }}
            <input type="password" name="wifi_password" maxlength="63" autocomplete="new-password" class="field font-mono">
            <span class="text-xs text-slate-500">{{ __('portal.toolbox.wifi_qr.password_help') }}</span></label>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="hidden" value="0">
            <input type="checkbox" name="hidden" value="1" @checked(old('hidden') === '1')> {{ __('portal.toolbox.wifi_qr.hidden') }}</label>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="show_password" value="0">
            <input type="checkbox" name="show_password" value="1" @checked(old('show_password', '1') === '1')> {{ __('portal.toolbox.wifi_qr.show_password') }}</label>
        <label class="label">{{ __('portal.toolbox.wifi_qr.format') }}
            <select name="format" class="field">
                <option value="a4" @selected(old('format', 'a4') === 'a4')>A4</option>
                <option value="a6" @selected(old('format') === 'a6')>A6</option>
            </select></label>
        <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.toolbox.wifi_qr.not_stored') }}</p>
        <div class="md:col-span-2 flex gap-2">
            <button class="btn">{{ __('portal.toolbox.wifi_qr.submit') }}</button>
            <a href="{{ route('admin.toolbox.index') }}" class="btn-light">{{ __('portal.back') }}</a>
        </div>
    </form>
</x-layouts.admin>
