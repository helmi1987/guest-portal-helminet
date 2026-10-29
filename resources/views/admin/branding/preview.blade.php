{{-- Live preview of colours (and saved custom CSS via the gp-* classes); updated by the script below. --}}
<div class="md:col-span-2">
    <span class="text-sm">{{ __('portal.branding.preview') }}</span>
    <div data-gp-preview class="mt-1 rounded border border-slate-300 bg-(--gp-bg) p-4 text-(--gp-text)">
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
        // Sets the --gp-* variables on the preview from the colour inputs ([data-gp-var]); invalid or
        // non-overridden values fall back to data-gp-fallback. Contrast colour as in App\Branding\Branding.
        document.querySelectorAll('[data-gp-preview]').forEach(function (preview) {
            var form = preview.closest('form');
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
            function apply() {
                form.querySelectorAll('[data-gp-var]').forEach(function (input) {
                    var toggle = input.dataset.gpToggle ? form.querySelector(input.dataset.gpToggle) : null;
                    var value = input.value.trim();
                    if ((toggle && !toggle.checked) || !/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value)) {
                        value = input.dataset.gpFallback;
                    }
                    preview.style.setProperty(input.dataset.gpVar, value);
                    if (input.dataset.gpVar === '--gp-accent') {
                        preview.style.setProperty('--gp-accent-contrast', contrast(value));
                    }
                });
            }
            form.addEventListener('input', apply);
            form.addEventListener('change', apply);
            apply();
        });
    </script>
@endonce
