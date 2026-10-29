{{-- Settings → Appearance: global logo, favicon, colours and custom CSS (docs/branding.md). --}}
@php($assets = app(\App\Branding\BrandingAssets::class))
<form method="POST" action="{{ route('admin.settings.branding') }}" enctype="multipart/form-data" id="branding"
      class="card mb-6 grid gap-4 md:grid-cols-2">
    @csrf
    <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.branding.title') }}</h2>
    <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.branding.help') }}</p>

    @foreach (['logo' => 'svg,png,jpg,webp', 'favicon' => 'svg,png,ico'] as $kind => $types)
        <div class="flex flex-col gap-2 text-sm">
            <span>{{ __('portal.branding.'.$kind) }}</span>
            <div class="flex min-h-12 items-center gap-3 rounded border border-slate-200 bg-(--gp-bg) p-2 text-(--gp-text)">
                @if ($values[$kind] && $assets->exists($values[$kind]))
                    <img src="{{ route('branding.file', ['file' => \App\Branding\BrandingAssets::fileName($values[$kind])], false) }}" alt=""
                         class="{{ $kind === 'logo' ? 'h-10' : 'size-8' }} w-auto max-w-40 object-contain">
                    <span class="text-xs">{{ __('portal.branding.current') }}</span>
                @else
                    @if ($kind === 'logo')
                        <x-branding.logo :branding="new \App\Branding\Branding(array_replace($values, ['logo' => null]))" size="h-10" />
                    @else
                        <img src="/favicon.svg" alt="" class="size-8">
                    @endif
                    <span class="text-xs">{{ __('portal.branding.default_'.$kind) }}</span>
                    @if ($values[$kind])
                        <span class="text-xs text-red-700">{{ __('portal.branding.missing_file') }}</span>
                    @endif
                @endif
            </div>
            <input type="file" name="{{ $kind }}" accept="{{ collect(explode(',', $types))->map(fn ($t) => '.'.$t)->implode(',') }}" class="field text-sm">
            <span class="text-xs text-slate-500">{{ __('portal.branding.file_help', ['types' => strtoupper(str_replace(',', ', ', $types)),
                'kb' => intdiv(config('portal.branding.'.$kind.'_max_bytes'), 1024)]) }}</span>
            @if ($values[$kind])
                <label class="flex items-center gap-2"><input type="checkbox" name="{{ $kind }}_remove" value="1">
                    {{ __('portal.branding.remove_'.$kind) }}</label>
            @endif
        </div>
    @endforeach

    <div class="grid gap-4 md:col-span-2 md:grid-cols-3">
        @foreach (\App\Branding\BrandingSettings::COLORS as $key)
            <label class="label">{{ __('portal.branding.'.$key) }}
                <input name="{{ $key }}" value="{{ old($key, $values[$key]) }}" maxlength="7" pattern="#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})"
                       placeholder="{{ \App\Branding\Branding::DEFAULTS[$key] }}" class="field font-mono"
                       data-gp-var="{{ ['accent' => '--gp-accent', 'background' => '--gp-bg', 'text' => '--gp-text'][$key] }}"
                       data-gp-fallback="{{ \App\Branding\Branding::DEFAULTS[$key] }}">
                <span class="text-xs text-slate-500">{{ __('portal.branding.color_help', ['default' => \App\Branding\Branding::DEFAULTS[$key]]) }}</span>
            </label>
        @endforeach
    </div>

    @include('admin.branding.preview', ['resolved' => new \App\Branding\Branding($values)])

    <label class="label md:col-span-2">{{ __('portal.branding.css') }}
        <textarea name="css" rows="10" spellcheck="false" class="field font-mono text-xs">{{ old('css', $values['css']) }}</textarea>
        <span class="text-xs text-slate-500">{{ __('portal.branding.css_help', ['kb' => intdiv(config('portal.branding.css_max_bytes'), 1024)]) }}</span>
    </label>
    <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.branding.plain_hint') }}</p>
    <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
</form>
