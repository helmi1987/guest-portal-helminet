{{-- Guest network form of the wizard (decision 2026-11-02): the setup page's network form split into the
     groups network, wifi and hotspot. updateNetwork() validates the whole form, so the fields of the other
     groups are posted as hidden inputs with their current values (saved, else derived like the setup page);
     the Wi-Fi password is never posted from here (empty keeps the stored one), masters and CAPs not posted
     keep their stored selection. Needs: $site, $group, $net, $mark, $setup, $wifiModes, the wifi-modes
     partial's data, $wifiPasswordSet, $wifiPasswordShared, $wifiPasswordUnavailable, $stepNumber. --}}
@php
    $networkFields = ['vlan_id', 'bridge', 'interface', 'subnet', 'gateway', 'pool'];
    $selectedModes = array_values(array_filter((array) old('wifi_modes', $wifiModes), 'is_string'));
@endphp
<form method="POST" action="{{ route('admin.sites.setup.network', $site) }}" class="mb-4 grid gap-3 md:grid-cols-4" data-wizard-form="{{ $group }}">
    @csrf
    <input type="hidden" name="wizard_step" value="{{ $stepNumber }}">
    @if ($errors->any())
        <ul class="md:col-span-4 rounded border border-red-300 p-2 text-sm text-red-700" role="alert">
            @foreach ($errors->all() as $message) <li>{{ $message }}</li> @endforeach
        </ul>
    @endif
    @if ($group === 'network')
        <label class="label">{{ __('portal.setup.network.vlan_id') }}{{ $mark('vlan_id') }}
            <input name="vlan_id" type="number" min="1" max="4094" value="{{ $net('vlan_id') }}" placeholder="30" required class="field"></label>
        <label class="label md:col-span-2">{{ __('portal.setup.network.bridge') }}{{ $mark('bridge') }}
            <input name="bridge" value="{{ $net('bridge') }}" placeholder="bridge1" required class="field">
            <span class="text-xs text-slate-500">{{ __('portal.setup.network.bridge_help') }}</span></label>
        <label class="label">{{ __('portal.setup.network.interface') }}{{ $mark('interface') }}
            <input name="interface" value="{{ $net('interface') }}" placeholder="vlan30-guest" required class="field"></label>
        <label class="label">{{ __('portal.setup.network.subnet') }}{{ $mark('subnet') }}
            <input name="subnet" value="{{ $net('subnet') }}" placeholder="10.30.0.0/24" required class="field"></label>
        <label class="label">{{ __('portal.setup.network.gateway') }}{{ $mark('gateway') }}
            <input name="gateway" value="{{ $net('gateway') }}" placeholder="10.30.0.1" required class="field"></label>
        <label class="label">{{ __('portal.setup.network.pool') }}{{ $mark('pool') }}
            <input name="pool" value="{{ $net('pool') }}" placeholder="10.30.0.10-10.30.0.250" required class="field"></label>
    @else
        @foreach ($networkFields as $name)
            <input type="hidden" name="{{ $name }}" value="{{ $net($name) }}">
        @endforeach
    @endif
    @if ($group === 'wifi')
        <label class="label">{{ __('portal.setup.network.ssid') }}{{ $mark('ssid') }}
            <input name="ssid" value="{{ $net('ssid') }}" placeholder="Guest-WiFi" required class="field"></label>
        {{-- Never rendered back in clear: empty keeps the stored password, the checkbox removes it --}}
        <label class="label md:col-span-3">{{ __('portal.setup.network.wifi_password') }}
            <input name="wifi_password" type="password" autocomplete="new-password" minlength="8" maxlength="63" class="field"
                @if ($wifiPasswordSet) placeholder="••••••••" @endif>
            <span class="text-xs text-slate-500">{{ $wifiPasswordSet ? __('portal.setup.network.wifi_password_stored').' ' : '' }}{{ __('portal.setup.network.wifi_password_help') }}</span>
            @if ($wifiPasswordSet)
                <span class="text-xs"><input type="checkbox" name="wifi_password_clear" value="1"> {{ __('portal.setup.network.wifi_password_clear') }}</span>
            @endif
            <span class="text-xs"><input type="checkbox" name="wifi_password_shared" value="1" @checked($wifiPasswordShared)> {{ __('portal.setup.network.wifi_password_shared') }}</span>
            <span class="text-xs text-slate-500">{{ __('portal.setup.network.wifi_password_shared_help') }}</span>
            @if ($wifiPasswordUnavailable)
                <span class="text-xs text-amber-800">{{ __('portal.setup.network.wifi_password_unavailable') }}</span>
            @endif
        </label>
        @include('admin.sites.partials.wifi-modes', ['selectedModes' => $selectedModes])
    @else
        <input type="hidden" name="ssid" value="{{ $net('ssid') }}">
        @foreach ($selectedModes as $mode)
            <input type="hidden" name="wifi_modes[]" value="{{ $mode }}">
        @endforeach
        {{-- Unticked checkbox = not shared: keep the current choice --}}
        @if ($wifiPasswordShared) <input type="hidden" name="wifi_password_shared" value="1"> @endif
    @endif
    @if ($group === 'hotspot')
        <label class="label md:col-span-2">{{ __('portal.setup.network.html_directory') }}{{ $mark('html_directory') }}
            <select name="html_directory" class="field">
                @foreach (\App\Nas\MikrotikRos7Driver::HTML_DIRECTORIES as $dir)
                    <option value="{{ $dir }}" @selected($net('html_directory', 'hotspot') === $dir)>{{ $dir }}</option>
                @endforeach
            </select>
            <span class="text-xs text-slate-500">{{ __('portal.setup.network.html_directory_help') }}</span></label>
    @else
        <input type="hidden" name="html_directory" value="{{ $net('html_directory', 'hotspot') }}">
    @endif
    <div class="md:col-span-4"><button class="btn">{{ __('portal.save') }}</button></div>
</form>
