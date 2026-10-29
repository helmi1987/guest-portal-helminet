{{-- Guest pages: light, mobile-first, no Livewire; must work in captive-portal mini browsers without JS.
     gp-* classes are the stable styling hooks for custom CSS (docs/branding.md). --}}
@props(['step' => null, 'variant' => null, 'title' => null])
@php($branding = \App\Branding\BrandingSettings::forGuest(request()))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
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
</body>
</html>
