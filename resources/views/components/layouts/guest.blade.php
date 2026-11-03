{{-- Guest pages: mobile-first, no Livewire; must work in captive-portal mini browsers without JS.
     gp-* classes are the stable styling hooks for custom CSS (docs/branding.md). --}}
@props(['step' => null, 'variant' => null, 'title' => null])
@php($branding = \App\Branding\BrandingSettings::forGuest(request()))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="{{ $branding->themeClass() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    {{ $head ?? '' }}
    <title>{{ $title ?? __('portal.guest.title') }}</title>
    @vite(['resources/css/app.css'])
    <x-branding.head :branding="$branding" />
</head>
<body class="{{ $branding->bodyClasses('guest', $step, $variant) }} min-h-screen bg-(--gp-bg) text-(--gp-text) antialiased">
    <div class="gp-container mx-auto flex min-h-screen w-full max-w-md flex-col px-4 py-4 sm:py-10">
        <header class="gp-header mb-4 flex items-center justify-between gap-2">
            <span class="flex min-w-0 items-center gap-2">
                <x-branding.logo :branding="$branding" />
                <span class="gp-title font-semibold opacity-80">{{ __('portal.guest.title') }}</span>
            </span>
            <x-language-switcher />
        </header>
        <main class="gp-main flex flex-1 flex-col gap-4">
            {{ $slot }}
        </main>
    </div>
    @if ($guestPreview)
        {{-- Admin preview (CONCEPT.md §9 "Guest texts"): a click inside the iframe asks the admin page to
             re-post its drafts for the next screen, so unsaved texts are kept; plain links work without JS. --}}
        <script>
        (function () {
            if (window.parent === window) { return; }
            var path = @json(parse_url(route('admin.texts.preview'), PHP_URL_PATH));
            var send = function (data) { window.parent.postMessage(data, location.origin); };
            document.addEventListener('click', function (e) {
                var a = e.target.closest ? e.target.closest('a[href]') : null;
                if (!a || a.getAttribute('href').charAt(0) === '#') { return; }
                var url = new URL(a.href, location.href);
                if (url.origin !== location.origin || url.pathname !== path) { return; }
                e.preventDefault();
                send({screen: url.searchParams.get('screen'), locale: url.searchParams.get('locale')});
            });
            document.addEventListener('submit', function (e) {
                var screen = e.target.elements ? e.target.elements.namedItem('screen') : null;
                if (!screen) { return; }
                e.preventDefault();
                send({screen: screen.value});
            });
        })();
        </script>
    @endif
</body>
</html>
