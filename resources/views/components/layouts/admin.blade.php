{{-- Admin UI: global branding only (no site overrides). ?plain=1 skips the theme stylesheet, e.g. to
     repair broken custom CSS. gp-* classes are stable styling hooks (docs/branding.md). --}}
@php($branding = \App\Branding\BrandingSettings::forAdmin())
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="{{ $branding->themeClass() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ __('portal.admin.title') }}</title>
    @vite(['resources/css/app.css'])
    <x-branding.head :branding="$branding" :theme="! request()->boolean('plain')" />
    @livewireStyles
</head>
<body class="{{ $branding->bodyClasses('admin') }} min-h-screen bg-(--gp-bg) text-(--gp-text) antialiased">
    @auth
        <header class="gp-header bg-(--gp-header-bg) text-(--gp-header-text)">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 p-4">
                <nav class="gp-nav flex flex-wrap items-center gap-4">
                    <a href="{{ route('admin.dashboard') }}" class="gp-title flex items-center gap-2 font-semibold"><x-branding.logo :branding="$branding" size="h-6" class="text-(--gp-header-text)" />{{ __('portal.admin.title') }}</a>
                    <a href="{{ route('admin.grants.index') }}">{{ __('portal.grants.title') }}</a>
                    <a href="{{ route('admin.approvals.index') }}">{{ __('portal.approvals.title') }}</a>
                    <a href="{{ route('admin.guests.index') }}">{{ __('portal.guests.title') }}</a>
                    <a href="{{ route('admin.whitelist.index') }}">{{ __('portal.whitelist.title') }}</a>
                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('admin.sites.index') }}">{{ __('portal.sites.title') }}</a>
                        <a href="{{ route('admin.terms') }}">{{ __('portal.terms.title') }}</a>
                        <a href="{{ route('admin.settings') }}">{{ __('portal.admin.settings') }}</a>
                        <a href="{{ route('admin.users.index') }}">{{ __('portal.users.title') }}</a>
                        <a href="{{ route('admin.audit') }}">{{ __('portal.audit.title') }}</a>
                        <a href="{{ route('admin.certificates.index') }}">{{ __('portal.certificates.title') }}</a>
                        <a href="{{ route('admin.backups.index') }}">{{ __('portal.backups.title') }}</a>
                    @endif
                </nav>
                <div class="gp-user-menu flex items-center gap-4 text-sm">
                    <a href="{{ route('admin.profile') }}" class="underline">{{ auth()->user()->name }} ({{ auth()->user()->role->label() }})</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="underline">{{ __('portal.auth.logout') }}</button>
                    </form>
                </div>
            </div>
        </header>
    @endauth
    <main class="gp-main mx-auto max-w-5xl p-6">
        <x-admin.flash />
        {{ $slot }}
    </main>
    <footer class="gp-footer mx-auto max-w-5xl px-6 pb-6 text-xs opacity-60">v{{ config('portal.version') }}</footer>
    @livewireScripts
</body>
</html>
