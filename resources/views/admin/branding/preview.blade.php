{{-- Live preview of colour scheme and colours (and saved custom CSS via the gp-* classes); updated by the script below. --}}
<div class="md:col-span-2">
    <span class="text-sm">{{ __('portal.branding.preview') }}</span>
    {{-- The light preview gets a fixed light border (border-slate-300 is remapped on dark pages). --}}
    <div data-gp-preview data-gp-defaults="{{ json_encode(\App\Branding\Branding::DEFAULTS) }}"
         data-gp-derived="{{ json_encode(\App\Branding\Branding::DERIVED) }}"
         class="mt-1 rounded border border-slate-300 bg-(--gp-bg) p-4 text-(--gp-text) data-[gp-scheme=light]:border-[#cbd5e1]">
        <div class="gp-header mb-3 flex items-center gap-2">
            <x-branding.logo :branding="$resolved" />
            <span class="gp-title font-semibold opacity-80">{{ __('portal.guest.title') }}</span>
        </div>
        <div class="gp-card card">
            <p class="gp-card-title mb-1 text-lg font-semibold">{{ __('portal.branding.preview_title') }}</p>
            <p class="gp-intro mb-3 text-sm opacity-80">{{ __('portal.branding.preview_text') }}</p>
            <span class="gp-button btn">{{ __('portal.guest.connect') }}</span>
            <a href="#" class="gp-link ml-3 text-sm underline">{{ __('portal.branding.preview_link') }}</a>
        </div>
    </div>
</div>
@once
    <script>
        // Sets the colour scheme ([data-gp-scheme-input]) and the --gp-* variables ([data-gp-var]) on the
        // preview. Not overridden (data-gp-toggle off) or invalid values fall back to data-gp-fallback,
        // then to the scheme default. Contrast colour and derived variables (custom background or text
        // colour) as in App\Branding\Branding.
        document.querySelectorAll('[data-gp-preview]').forEach(function (preview) {
            var form = preview.closest('form');
            var defaults = JSON.parse(preview.dataset.gpDefaults);
            var derived = JSON.parse(preview.dataset.gpDerived);
            var page = document.documentElement.classList.contains('gp-theme-light') ? 'light' : 'dark';
            function expand(hex) {
                hex = hex.replace('#', '');
                return hex.length === 3 ? hex.split('').map(function (c) { return c + c; }).join('') : hex;
            }
            function contrast(hex) {
                var c = expand(hex).match(/../g).map(function (x) {
                    var v = parseInt(x, 16) / 255;
                    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
                });
                var l = 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
                return 1.05 / (l + 0.05) >= (l + 0.05) / 0.05 ? '#ffffff' : '#000000';
            }
            function overridden(input) {
                var toggle = input.dataset.gpToggle ? form.querySelector(input.dataset.gpToggle) : null;
                return !toggle || toggle.checked;
            }
            function apply() {
                var select = form.querySelector('[data-gp-scheme-input]');
                var scheme = select ? (overridden(select) ? select.value : select.dataset.gpFallback) : page;
                // Same scheme as the page: inherit it, so saved custom CSS variables stay visible.
                if (scheme === page) {
                    preview.removeAttribute('data-gp-scheme');
                } else {
                    preview.setAttribute('data-gp-scheme', scheme);
                }
                var custom = false;
                form.querySelectorAll('[data-gp-var]').forEach(function (input) {
                    var value = input.value.trim();
                    if (!overridden(input) || !/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value)) {
                        value = input.dataset.gpFallback || '';
                    }
                    // A set (not default) background or text colour derives surface, inputs and borders.
                    if (value !== '' && input.dataset.gpKey !== 'accent') {
                        custom = true;
                    }
                    value = value || defaults[scheme][input.dataset.gpKey];
                    preview.style.setProperty(input.dataset.gpVar, value);
                    if (input.dataset.gpVar === '--gp-accent') {
                        preview.style.setProperty('--gp-accent-contrast', contrast(value));
                    }
                });
                Object.keys(derived).forEach(function (name) {
                    if (custom) {
                        preview.style.setProperty(name, derived[name]);
                    } else {
                        preview.style.removeProperty(name);
                    }
                });
            }
            form.addEventListener('input', apply);
            form.addEventListener('change', apply);
            apply();
        });
    </script>
@endonce
