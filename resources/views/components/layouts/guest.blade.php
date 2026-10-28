{{-- Guest pages: light, mobile-first, no Livewire; must work in captive-portal mini browsers without JS. --}}
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
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <div class="mx-auto flex min-h-screen w-full max-w-md flex-col px-4 py-4 sm:py-10">
        <header class="mb-4 flex items-center justify-between gap-2">
            <span class="font-semibold text-slate-700">{{ __('portal.guest.title') }}</span>
            <x-language-switcher />
        </header>
        <main class="flex flex-1 flex-col gap-4">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
