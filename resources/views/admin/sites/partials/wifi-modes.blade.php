{{-- Wi-Fi modes (any combination, decision 2026-11-02), masters per mode and the guest SSID per CAP of the
     CAPsMAN modes (both local to this instance). Fields of the setup network form; reusable (setup wizard).
     Needs: $selectedModes (list), $wifiMasters, $wifiMasterOptions, $wifiCaps, $wifiCapOptions, $mark (closure). --}}
<div class="md:col-span-4 grid gap-3 md:grid-cols-4" x-data="{ wifiModes: @js($selectedModes) }" data-wifi-modes>
    <fieldset class="label md:col-span-4">
        <legend>{{ __('portal.setup.network.wifi_mode') }}{{ $mark('wifi_modes') }}</legend>
        <span class="flex flex-wrap gap-x-4 gap-y-1">
            @foreach (['capsman2', 'capsman1', 'local'] as $mode)
                <span class="text-sm"><input type="checkbox" name="wifi_modes[]" value="{{ $mode }}" x-model="wifiModes" @checked(in_array($mode, $selectedModes, true))>
                    {{ __('portal.setup.network.modes.'.$mode) }}</span>
            @endforeach
        </span>
        <span class="text-xs text-slate-500">{{ __('portal.setup.network.wifi_modes_help') }}</span>
        @error('wifi_modes') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
        @error('wifi_modes.*') <span class="text-xs text-red-700">{{ $message }}</span> @enderror
    </fieldset>
    {{-- Guest Wi-Fi masters per mode (local to this instance): choices from the router state, else free text --}}
    @foreach (\App\Models\Site::WIFI_MODES as $mode)
        @php($chosen = array_map('strval', (array) old('wifi_masters.'.$mode, $wifiMasters[$mode] ?? [])))
        @php($choices = array_values(array_unique([...$wifiMasterOptions[$mode] ?? [], ...$chosen])))
        @php($shown = in_array($mode, $selectedModes, true))
        <fieldset class="label md:col-span-2" data-wifi-masters="{{ $mode }}" x-show="wifiModes.includes(@js($mode))" x-bind:disabled="!wifiModes.includes(@js($mode))" @if (! $shown) style="display: none" disabled @endif>
            <legend>{{ __('portal.setup.network.wifi_masters.'.$mode) }}</legend>
            @if (($wifiMasterOptions[$mode] ?? []) !== [])
                <span class="flex flex-wrap gap-x-4 gap-y-1">
                    @foreach ($choices as $choice)
                        <span class="text-sm"><input type="checkbox" name="wifi_masters[{{ $mode }}][]" value="{{ $choice }}" @checked(in_array($choice, $chosen, true))>
                            {{ $choice }}@if (! in_array($choice, $wifiMasterOptions[$mode], true)) <span class="text-xs text-amber-800">({{ __('portal.setup.network.wifi_masters_not_found') }})</span>@endif</span>
                    @endforeach
                </span>
                <input name="wifi_masters_text[{{ $mode }}]" value="{{ old('wifi_masters_text.'.$mode) }}" class="field" placeholder="{{ __('portal.setup.network.wifi_masters_more') }}">
            @else
                <input name="wifi_masters_text[{{ $mode }}]" value="{{ old('wifi_masters_text.'.$mode, implode(', ', $chosen)) }}" class="field"
                    placeholder="{{ $mode === 'local' ? 'wifi1, wifi2' : 'cfg-main, cfg-office' }}">
            @endif
            <span class="text-xs text-slate-500">{{ __('portal.setup.network.wifi_masters_help.'.$mode) }} {{ __('portal.setup.network.wifi_masters_local') }}</span>
            @error('wifi_masters_text.'.$mode) <span class="text-xs text-red-700">{{ $message }}</span> @enderror
        </fieldset>
        @if (in_array($mode, \App\Models\Site::CAPSMAN_MODES, true))
            {{-- Guest SSID per CAP (variant A): own provisioning rule per selected radio; empty = every CAP of the masters --}}
            @php($capsChosen = array_map('strval', (array) old('wifi_caps.'.$mode, $wifiCaps[$mode] ?? [])))
            @php($capOptions = collect($wifiCapOptions[$mode] ?? [])->keyBy('mac'))
            <fieldset class="label md:col-span-2" data-wifi-caps="{{ $mode }}" x-show="wifiModes.includes(@js($mode))" x-bind:disabled="!wifiModes.includes(@js($mode))" @if (! $shown) style="display: none" disabled @endif>
                <legend>{{ __('portal.setup.network.wifi_caps.'.$mode) }}</legend>
                @if ($capOptions->isNotEmpty())
                    <span class="flex flex-col gap-y-1">
                        @foreach ($capOptions->keys()->merge($capsChosen)->unique() as $mac)
                            <span class="text-sm"><input type="checkbox" name="wifi_caps[{{ $mode }}][]" value="{{ $mac }}" @checked(in_array($mac, $capsChosen, true))>
                                <code>{{ $mac }}</code>
                                @if ($capOptions->has($mac))
                                    {{ $capOptions[$mac]['label'] }}@if ($capOptions[$mac]['configuration'] !== '') <span class="text-xs text-slate-500">({{ $capOptions[$mac]['configuration'] }})</span>@endif
                                @else
                                    <span class="text-xs text-amber-800">({{ __('portal.setup.network.wifi_masters_not_found') }})</span>
                                @endif
                            </span>
                        @endforeach
                    </span>
                    <input name="wifi_caps_text[{{ $mode }}]" value="{{ old('wifi_caps_text.'.$mode) }}" class="field" placeholder="{{ __('portal.setup.network.wifi_masters_more') }}">
                @else
                    <input name="wifi_caps_text[{{ $mode }}]" value="{{ old('wifi_caps_text.'.$mode, implode(', ', $capsChosen)) }}" class="field" placeholder="AA:BB:CC:DD:EE:01, AA:BB:CC:DD:EE:02">
                @endif
                <span class="text-xs text-slate-500">{{ __('portal.setup.network.wifi_caps_help') }} {{ __('portal.setup.network.wifi_masters_local') }}</span>
                @if ($mode === 'capsman1') <span class="text-xs text-amber-800">{{ __('portal.setup.network.wifi_caps_untested') }}</span> @endif
                @error('wifi_caps_text.'.$mode) <span class="text-xs text-red-700">{{ $message }}</span> @enderror
            </fieldset>
        @endif
    @endforeach
</div>
