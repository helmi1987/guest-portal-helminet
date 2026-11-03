{{-- Guest texts per language with a live preview (CONCEPT.md §9 "Guest texts"). The iframe shows the real guest
     views; the script posts the unsaved drafts to it (debounced) and follows clicks inside the preview. Without
     JS the preview shows the saved texts and its links and the screen selector still work. --}}
@php($texts = \App\Guest\GuestTexts::class)
@php($screens = \App\Guest\GuestPreview::screens())
<x-layouts.admin :title="__('portal.texts.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.texts.title') }}</h1>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.texts.help') }}</p>

    <nav class="mb-6 flex flex-wrap gap-2" aria-label="{{ __('portal.language') }}">
        @foreach (config('portal.locales') as $code)
            <a href="{{ route('admin.texts', array_filter(['locale' => $code, 'site' => request('site'), 'screen' => $screen])) }}" data-gt-locale="{{ $code }}"
               @class(['btn' => $code === $locale, 'btn-light' => $code !== $locale]) @if ($code === $locale) aria-current="page" @endif>{{ __('portal.languages.'.$code) }}</a>
        @endforeach
    </nav>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_400px]">
        <form id="gt-form" method="POST" action="{{ route('admin.texts.update', $locale) }}" class="flex min-w-0 flex-col gap-6" lang="{{ $locale }}">
            @csrf
            <input type="hidden" name="site" value="{{ request('site') }}">
            <input type="hidden" name="screen" value="{{ $screen }}" data-gt-screen-field>
            @foreach ($texts::KEYS as $group => $keys)
                <section class="card" id="texts-{{ $group }}">
                    <h2 class="mb-3 text-lg font-semibold">{{ __('portal.texts.groups.'.$group) }}</h2>
                    <div class="flex flex-col gap-4">
                        @foreach ($keys as $key)
                            @php($field = $texts::field($key))
                            @php($default = $texts::default($key, $locale))
                            @php($value = old('texts.'.$field, $overrides[$key] ?? ''))
                            @php($placeholders = $texts::placeholders($key, $locale))
                            <div class="flex flex-col gap-1" data-gt-field>
                                <label for="gt-{{ $field }}" class="text-sm font-medium">{{ \App\Http\Controllers\Admin\GuestTextsController::label($key) }}</label>
                                @if (mb_strlen($default) > 70)
                                    <textarea id="gt-{{ $field }}" name="texts[{{ $field }}]" rows="3" maxlength="{{ $texts::MAX }}" placeholder="{{ $default }}"
                                              data-gt-input data-screens="{{ implode(' ', \App\Guest\GuestPreview::screensFor($key)) }}"
                                              @class(['field text-sm', 'border-red-500' => $errors->has('texts.'.$field)])>{{ $value }}</textarea>
                                @else
                                    <input id="gt-{{ $field }}" name="texts[{{ $field }}]" value="{{ $value }}" maxlength="{{ $texts::MAX }}" placeholder="{{ $default }}"
                                           data-gt-input data-screens="{{ implode(' ', \App\Guest\GuestPreview::screensFor($key)) }}"
                                           @class(['field text-sm', 'border-red-500' => $errors->has('texts.'.$field)])>
                                @endif
                                @error('texts.'.$field)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 text-xs text-slate-500">
                                    <span class="min-w-0 flex-1" data-gt-default @if ($value === '') hidden @endif>{{ __('portal.texts.default_text', ['text' => $default]) }}</span>
                                    @if ($placeholders !== [])
                                        <span>{{ __('portal.texts.placeholders', ['list' => implode(', ', array_map(fn ($p) => ':'.$p, $placeholders))]) }}</span>
                                    @endif
                                    <button type="button" class="underline" data-gt-reset hidden>{{ __('portal.texts.reset') }}</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
            <p class="sticky bottom-0 z-10 flex flex-wrap items-center gap-3 rounded bg-(--gp-bg) py-3">
                <button type="submit" class="btn">{{ __('portal.texts.save', ['language' => __('portal.languages.'.$locale)]) }}</button>
                <span class="text-xs text-slate-500">{{ __('portal.texts.empty_hint') }}</span>
            </p>
        </form>

        <aside class="flex min-w-0 flex-col gap-3 lg:sticky lg:top-4" aria-label="{{ __('portal.texts.preview') }}">
            <p class="text-lg font-semibold">{{ __('portal.texts.preview') }}</p>
            <form method="GET" action="{{ route('admin.texts.preview') }}" target="preview" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="locale" value="{{ $locale }}">
                @if ($site)<input type="hidden" name="site" value="{{ $site->id }}">@endif
                <label class="label min-w-0 flex-1">{{ __('portal.texts.preview_screen') }}
                    <select name="screen" id="gt-screen" class="field">
                        @foreach ($screens as $option)
                            <option value="{{ $option }}" @selected($option === $screen)>{{ \App\Guest\GuestPreview::screenLabel($option) }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="btn-light" data-gt-nojs>{{ __('portal.texts.preview_show') }}</button>
            </form>
            @if ($sites->count() > 1)
                <form method="GET" action="{{ route('admin.texts') }}" class="flex flex-wrap items-end gap-2" id="gt-site-form">
                    <input type="hidden" name="locale" value="{{ $locale }}">
                    <input type="hidden" name="screen" value="{{ $screen }}" data-gt-screen-field>
                    <label class="label min-w-0 flex-1">{{ __('portal.texts.site') }}
                        <select name="site" class="field">
                            @foreach ($sites as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $site?->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="btn-light" data-gt-nojs>{{ __('portal.texts.site_show') }}</button>
                </form>
            @elseif ($site === null)
                <p class="text-xs text-slate-500">{{ __('portal.texts.no_site') }}</p>
            @endif
            <iframe name="preview" id="gt-preview" title="{{ __('portal.texts.preview_title') }}"
                    src="{{ route('admin.texts.preview', array_filter(['locale' => $locale, 'screen' => $screen, 'site' => $site?->id])) }}"
                    class="h-[44rem] w-full rounded border border-(--gp-border) bg-white"></iframe>
            <p class="text-xs text-slate-500">{{ __('portal.texts.preview_hint') }}</p>
        </aside>
    </div>

    {{-- Drafts for the preview: filled from the form by the script, never stored. --}}
    <form id="gt-preview-form" method="POST" action="{{ route('admin.texts.preview.draft') }}" target="preview" hidden>
        @csrf
        <input type="hidden" name="locale" value="{{ $locale }}">
        <input type="hidden" name="screen" value="{{ $screen }}">
        @if ($site)<input type="hidden" name="site" value="{{ $site->id }}">@endif
    </form>

    <script>
    (function () {
        var d = document, form = d.getElementById('gt-form'), drafts = d.getElementById('gt-preview-form');
        var frame = d.getElementById('gt-preview'), select = d.getElementById('gt-screen');
        var screens = Array.prototype.map.call(select.options, function (o) { return o.value; });
        var timer = null, dirty = false;
        var each = function (sel, fn, root) { Array.prototype.forEach.call((root || d).querySelectorAll(sel), fn); };

        each('[data-gt-nojs]', function (el) { el.hidden = true; });

        var refresh = function () {
            clearTimeout(timer);
            each('[data-gt-draft]', function (n) { n.remove(); }, drafts);
            each('[data-gt-input]', function (input) {
                var h = d.createElement('input');
                h.type = 'hidden'; h.name = input.name; h.value = input.value; h.setAttribute('data-gt-draft', '');
                drafts.appendChild(h);
            }, form);
            drafts.elements.namedItem('screen').value = select.value;
            each('[data-gt-screen-field]', function (f) { f.value = select.value; });
            drafts.submit();
        };
        var later = function () { clearTimeout(timer); timer = setTimeout(refresh, 300); };
        var show = function (screen) { if (screens.indexOf(screen) >= 0) { select.value = screen; refresh(); } };

        // Default text and reset button follow the field: shown while an own text is entered.
        var sync = function (input) {
            var box = input.closest('[data-gt-field]');
            box.querySelector('[data-gt-default]').hidden = input.value === '';
            box.querySelector('[data-gt-reset]').hidden = input.value === '';
        };
        each('[data-gt-input]', sync, form);
        form.addEventListener('input', function (e) {
            if (!e.target.hasAttribute('data-gt-input')) { return; }
            dirty = true; sync(e.target); later();
        });
        form.addEventListener('focusin', function (e) {
            var list = (e.target.getAttribute('data-screens') || '').split(' ').filter(Boolean);
            if (list.length && list.indexOf(select.value) < 0) { show(list[0]); }
        });
        form.addEventListener('click', function (e) {
            var reset = e.target.closest('[data-gt-reset]');
            if (!reset) { return; }
            var input = reset.closest('[data-gt-field]').querySelector('[data-gt-input]');
            input.value = ''; dirty = true; sync(input); refresh(); input.focus();
        });
        form.addEventListener('submit', function () { dirty = false; each('[data-gt-screen-field]', function (f) { f.value = select.value; }); });
        select.addEventListener('change', refresh);
        var siteForm = d.getElementById('gt-site-form');
        if (siteForm) {
            siteForm.addEventListener('change', function () {
                siteForm.elements.namedItem('screen').value = select.value;
                siteForm.submit();
            });
        }
        // Language tabs keep the shown screen.
        each('[data-gt-locale]', function (a) {
            a.addEventListener('click', function () {
                var url = new URL(a.href); url.searchParams.set('screen', select.value); a.href = url.toString();
            });
        });
        window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

        // Clicks inside the preview (guest layout script): next screen with the same drafts, or another language.
        window.addEventListener('message', function (e) {
            if (e.origin !== location.origin || e.source !== frame.contentWindow || !e.data || typeof e.data !== 'object') { return; }
            var tab = e.data.locale ? d.querySelector('[data-gt-locale="' + String(e.data.locale).replace(/[^a-z]/g, '') + '"]') : null;
            if (tab && tab.getAttribute('aria-current') === null) {
                if (screens.indexOf(e.data.screen) >= 0) { select.value = e.data.screen; }
                tab.click();
                return;
            }
            show(e.data.screen);
        });
        // After a failed save the form holds the rejected drafts: show them.
        if (form.querySelector('.border-red-500')) { refresh(); }
    })();
    </script>
</x-layouts.admin>
