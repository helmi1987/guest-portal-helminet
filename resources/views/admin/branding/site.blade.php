{{-- Site page → Appearance: per-item override of the global branding, unchecked = inherit (docs/branding.md). --}}
@php
    $assets = app(\App\Branding\BrandingAssets::class);
    $effective = array_replace($global, $overrides);
    $vars = ['accent' => '--gp-accent', 'background' => '--gp-bg', 'text' => '--gp-text'];
@endphp
<form method="POST" action="{{ route('admin.sites.branding', $site) }}" enctype="multipart/form-data" id="branding"
      class="card mb-6 grid gap-4 md:grid-cols-2">
    @csrf
    <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.branding.site_title') }}</h2>
    <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.branding.site_help') }}</p>

    @foreach (['logo' => 'svg,png,jpg,webp', 'favicon' => 'svg,png,ico'] as $kind => $types)
        <div class="flex flex-col gap-2 text-sm">
            <label class="flex items-center gap-2 font-medium">
                <input type="hidden" name="override[{{ $kind }}]" value="0">
                <input type="checkbox" name="override[{{ $kind }}]" value="1" @checked(old("override.$kind", isset($overrides[$kind])))>
                {{ __('portal.branding.override', ['item' => __('portal.branding.'.$kind)]) }}</label>
            <div class="flex min-h-12 items-center gap-3 rounded border border-slate-200 p-2">
                @if ($effective[$kind] && $assets->exists($effective[$kind]))
                    <img src="{{ route('branding.file', ['file' => \App\Branding\BrandingAssets::fileName($effective[$kind])], false) }}" alt=""
                         class="{{ $kind === 'logo' ? 'h-10' : 'size-8' }} w-auto max-w-40 object-contain">
                @elseif ($kind === 'logo')
                    <x-branding.logo :branding="new \App\Branding\Branding(array_replace($effective, ['logo' => null]))" size="h-10" />
                @else
                    <img src="/favicon.svg" alt="" class="size-8">
                @endif
                <span class="text-xs">{{ isset($overrides[$kind]) ? __('portal.branding.site_value')
                    : ($global[$kind] ? __('portal.branding.inherited') : __('portal.branding.default_'.$kind)) }}</span>
            </div>
            <input type="file" name="{{ $kind }}" accept="{{ collect(explode(',', $types))->map(fn ($t) => '.'.$t)->implode(',') }}" class="field text-sm">
            <span class="text-xs text-slate-500">{{ __('portal.branding.file_help', ['types' => strtoupper(str_replace(',', ', ', $types)),
                'kb' => intdiv(config('portal.branding.'.$kind.'_max_bytes'), 1024)]) }}</span>
        </div>
    @endforeach

    <div class="grid gap-4 md:col-span-2 md:grid-cols-3">
        @foreach (\App\Branding\BrandingSettings::COLORS as $key)
            @php($fallback = $global[$key] ?? \App\Branding\Branding::DEFAULTS[$key])
            <div class="flex flex-col gap-1 text-sm">
                <label class="flex items-center gap-2 font-medium">
                    <input type="hidden" name="override[{{ $key }}]" value="0">
                    <input type="checkbox" name="override[{{ $key }}]" value="1" id="branding-override-{{ $key }}"
                           @checked(old("override.$key", isset($overrides[$key])))>
                    {{ __('portal.branding.override', ['item' => __('portal.branding.'.$key)]) }}</label>
                <input name="{{ $key }}" value="{{ old($key, $overrides[$key] ?? '') }}" maxlength="7" pattern="#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})"
                       placeholder="{{ $fallback }}" aria-label="{{ __('portal.branding.'.$key) }}" class="field font-mono"
                       data-gp-var="{{ $vars[$key] }}" data-gp-fallback="{{ $fallback }}" data-gp-toggle="#branding-override-{{ $key }}">
                <span class="text-xs text-slate-500">{{ __('portal.branding.inherit_value', ['value' => $fallback]) }}</span>
            </div>
        @endforeach
    </div>

    @include('admin.branding.preview', ['resolved' => \App\Branding\BrandingSettings::resolve($site)])

    <div class="flex flex-col gap-1 text-sm md:col-span-2">
        <label class="flex items-center gap-2 font-medium">
            <input type="hidden" name="override[css]" value="0">
            <input type="checkbox" name="override[css]" value="1" @checked(old('override.css', array_key_exists('css', $overrides)))>
            {{ __('portal.branding.override', ['item' => __('portal.branding.css')]) }}</label>
        <textarea name="css" rows="8" spellcheck="false" aria-label="{{ __('portal.branding.css') }}"
                  class="field font-mono text-xs">{{ old('css', $overrides['css'] ?? '') }}</textarea>
        <span class="text-xs text-slate-500">{{ __('portal.branding.site_css_help', ['kb' => intdiv(config('portal.branding.css_max_bytes'), 1024)]) }}</span>
    </div>
    <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
</form>
