<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ __('portal.admin.title') }}</title>
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    @auth
        <header class="bg-slate-900 text-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 p-4">
                <nav class="flex flex-wrap gap-4">
                    <a href="{{ route('admin.dashboard') }}" class="font-semibold">{{ __('portal.admin.title') }}</a>
                    <a href="{{ route('admin.grants.index') }}">{{ __('portal.grants.title') }}</a>
                    <a href="{{ route('admin.approvals.index') }}">{{ __('portal.approvals.title') }}</a>
                    <a href="{{ route('admin.guests.index') }}">{{ __('portal.guests.title') }}</a>
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
                <div class="flex items-center gap-4 text-sm">
                    <a href="{{ route('admin.profile') }}" class="underline">{{ auth()->user()->name }} ({{ auth()->user()->role->label() }})</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="underline">{{ __('portal.auth.logout') }}</button>
                    </form>
                </div>
            </div>
        </header>
    @endauth
    <main class="mx-auto max-w-5xl p-6">
        <x-admin.flash />
        {{ $slot }}
    </main>
    <footer class="mx-auto max-w-5xl px-6 pb-6 text-xs text-slate-500">v{{ config('portal.version') }}</footer>
    @livewireScripts
</body>
</html>
