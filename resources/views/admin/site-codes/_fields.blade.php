{{-- Duration per device, optional expiry and device limit of a site code ($code: current code or null). --}}
@php($presets = config('portal.duration_presets'))
@php($preset = $code ? (array_search($code->duration_s, $presets, true) ?: 'custom') : '1d')
<label class="label">{{ __('portal.site_codes.duration') }}
    <select name="duration" class="field">
        @foreach (array_keys($presets) as $key)
            <option value="{{ $key }}" @selected(old('duration', $preset) === $key)>{{ __('portal.grants.presets.'.$key) }}</option>
        @endforeach
        <option value="custom" @selected(old('duration', $preset) === 'custom')>{{ __('portal.grants.presets.custom') }}</option>
    </select></label>
<label class="label">{{ __('portal.grants.custom_hours') }}
    <input type="number" step="0.25" min="0.25" max="8760" name="custom_hours"
           value="{{ old('custom_hours', $code && $preset === 'custom' ? round($code->duration_s / 3600, 2) : '') }}" class="field"></label>
<label class="label">{{ __('portal.site_codes.valid_until') }}
    <input type="datetime-local" name="valid_until" value="{{ old('valid_until', \App\Support\LocalTime::format($code?->valid_until, 'Y-m-d\TH:i')) }}" class="field">
    <span class="text-xs text-slate-500">{{ __('portal.site_codes.valid_until_help') }}</span></label>
<label class="label">{{ __('portal.site_codes.max_devices') }}
    <input type="number" min="1" max="100000" name="max_devices" value="{{ old('max_devices', $code?->max_devices) }}" class="field">
    <span class="text-xs text-slate-500">{{ __('portal.site_codes.max_devices_help') }}</span></label>
