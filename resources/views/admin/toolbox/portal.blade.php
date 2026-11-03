{{-- Guest portal as guests see it (toolbox, CONCEPT.md §9): the iframe loads the guest texts preview
     (`/admin/texts/preview`, the only framed route). A click inside the preview posts {screen, locale} to this
     page (guest layout script); the script then follows with the selectors and loads the next screen. Without
     JS the selectors are a plain GET form and the links inside the preview navigate the iframe directly. --}}
@php($widths = ['phone' => 'w-[390px]', 'desktop' => 'w-full'])
<x-layouts.admin :title="__('portal.toolbox.portal.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.toolbox.portal.title') }}</h1>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.toolbox.portal.help') }}</p>

    <form id="tp-form" method="GET" action="{{ route('admin.toolbox.portal') }}" class="card mb-4 flex flex-wrap items-end gap-3">
        <label class="label">{{ __('portal.toolbox.portal.language') }}
            <select name="locale" class="field">
                @foreach (config('portal.locales') as $code)
                    <option value="{{ $code }}" @selected($code === $locale)>{{ __('portal.languages.'.$code) }}</option>
                @endforeach
            </select>
        </label>
        @if ($sites->isNotEmpty())
            <label class="label">{{ __('portal.toolbox.portal.site') }}
                <select name="site" class="field">
                    @foreach ($sites as $option)
                        <option value="{{ $option->id }}" @selected($option->id === $site?->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="label min-w-48 flex-1">{{ __('portal.toolbox.portal.screen') }}
            <select name="screen" class="field">
                @foreach (\App\Guest\GuestPreview::screenGroups() as $group => $screens)
                    <optgroup label="{{ __('portal.toolbox.portal.groups.'.$group) }}">
                        @foreach ($screens as $option)
                            <option value="{{ $option }}" @selected($option === $screen)>{{ \App\Guest\GuestPreview::screenLabel($option) }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </label>
        <fieldset class="flex flex-col gap-1">
            <legend class="label">{{ __('portal.toolbox.portal.device') }}</legend>
            <div class="flex gap-3 py-2 text-sm">
                @foreach (array_keys($widths) as $option)
                    <label class="flex items-center gap-1">
                        <input type="radio" name="device" value="{{ $option }}" @checked($option === $device)> {{ __('portal.toolbox.portal.device_'.$option) }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <button type="submit" class="btn-light" data-tp-nojs>{{ __('portal.toolbox.portal.show') }}</button>
    </form>

    @if ($site === null)
        <p class="mb-2 text-xs text-slate-500">{{ __('portal.toolbox.portal.no_site') }}</p>
    @endif
    <div class="flex justify-center">
        <iframe name="portal-preview" id="tp-frame" title="{{ __('portal.toolbox.portal.frame_title') }}"
                src="{{ route('admin.texts.preview', array_filter(['locale' => $locale, 'screen' => $screen, 'site' => $site?->id])) }}"
                data-preview="{{ route('admin.texts.preview') }}" data-widths="{{ json_encode($widths) }}"
                class="{{ $widths[$device] }} h-[48rem] max-w-full rounded border border-(--gp-border) bg-white"></iframe>
    </div>
    <p class="mt-2 text-xs text-slate-500">{{ __('portal.toolbox.portal.hint') }}</p>

    <script>
    (function () {
        var d = document, form = d.getElementById('tp-form'), frame = d.getElementById('tp-frame');
        var widths = JSON.parse(frame.getAttribute('data-widths'));
        var field = function (name) { return form.elements.namedItem(name); };
        var has = function (select, value) {
            return Array.prototype.some.call(select.options, function (o) { return o.value === value; });
        };
        Array.prototype.forEach.call(d.querySelectorAll('[data-tp-nojs]'), function (el) { el.hidden = true; });

        // Selectors → iframe (preview URL) and address bar (reload keeps the view); device → width class.
        var load = function () {
            var params = new URLSearchParams({locale: field('locale').value, screen: field('screen').value});
            if (field('site')) { params.set('site', field('site').value); }
            frame.src = frame.getAttribute('data-preview') + '?' + params.toString();
            var device = form.querySelector('[name="device"]:checked').value;
            Object.keys(widths).forEach(function (key) { frame.classList.toggle(widths[key], key === device); });
            params.set('device', device);
            history.replaceState(null, '', location.pathname + '?' + params.toString());
        };
        form.addEventListener('change', load);
        form.addEventListener('submit', function (e) { e.preventDefault(); load(); });

        // Clicks inside the preview (guest layout script): the selectors follow, then the next screen loads.
        window.addEventListener('message', function (e) {
            if (e.origin !== location.origin || e.source !== frame.contentWindow || !e.data || typeof e.data !== 'object') { return; }
            var locale = String(e.data.locale || ''), screen = String(e.data.screen || '');
            if (has(field('locale'), locale)) { field('locale').value = locale; }
            if (has(field('screen'), screen)) { field('screen').value = screen; }
            load();
        });
    })();
    </script>
</x-layouts.admin>
