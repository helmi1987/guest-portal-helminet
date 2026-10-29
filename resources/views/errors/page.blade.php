{{-- Shared HTML error page (403/404/419/429/5xx): guest look on the guest port, admin look on the
     admin port. Self-contained: no Livewire, no auth, no database for 5xx (App\Support\ErrorPage);
     never shows exception details. gp-* classes are stable styling hooks (docs/branding.md). --}}
@php
    $page = \App\Support\ErrorPage::data((int) $status, $exception ?? null);
    $branding = $page['branding'];
    $key = 'portal.http_errors.'.$page['kind'];
    try {
        $assets = (string) app(\Illuminate\Foundation\Vite::class)(['resources/css/app.css']);
    } catch (\Throwable) {
        $assets = '';
    }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="{{ $branding->themeClass() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ __($key.'.title') }}</title>
    {!! $assets !!}
    <x-branding.head :branding="$branding" />
</head>
<body class="{{ $branding->bodyClasses($page['area'], 'error', 'http-'.$page['status']) }} gp-error-page min-h-screen bg-(--gp-bg) text-(--gp-text) antialiased">
@if ($page['area'] === 'admin')
    <header class="gp-header bg-(--gp-header-bg) text-(--gp-header-text)">
        <div class="mx-auto flex max-w-5xl items-center gap-4 p-4">
            <a href="{{ $page['back'] }}" class="gp-title flex items-center gap-2 font-semibold"><x-branding.logo :branding="$branding" size="h-6" class="text-(--gp-header-text)" />{{ __('portal.admin.title') }}</a>
        </div>
    </header>
    <main class="gp-main mx-auto max-w-5xl p-6">
        @include('errors.card')
    </main>
@else
    <div class="gp-container mx-auto flex min-h-screen w-full max-w-md flex-col px-4 py-4 sm:py-10">
        <header class="gp-header mb-4 flex items-center gap-2">
            <x-branding.logo :branding="$branding" />
            <span class="gp-title font-semibold opacity-80">{{ __('portal.guest.title') }}</span>
        </header>
        <main class="gp-main flex flex-1 flex-col gap-4">
            @include('errors.card')
        </main>
    </div>
@endif
</body>
</html>
