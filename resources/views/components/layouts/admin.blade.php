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
                    <details class="gp-nav-group relative">
                        <summary class="cursor-pointer select-none">{{ __('portal.nav.guests') }}</summary>
                        <div class="gp-nav-menu absolute left-0 z-20 mt-1 min-w-40 rounded bg-(--gp-header-bg) py-1 shadow-lg ring-1 ring-white/20">
                            <a href="{{ route('admin.grants.index') }}" class="block px-3 py-1 hover:underline">{{ __('portal.grants.title') }}</a>
                            <a href="{{ route('admin.site-codes.index') }}" class="block px-3 py-1 hover:underline">{{ __('portal.site_codes.title') }}</a>
                            <a href="{{ route('admin.approvals.index') }}" class="block px-3 py-1 hover:underline">{{ __('portal.approvals.title') }}</a>
                            <a href="{{ route('admin.guests.index') }}" class="block px-3 py-1 hover:underline">{{ __('portal.guests.title') }}</a>
                            <a href="{{ route('admin.whitelist.index') }}" class="block px-3 py-1 hover:underline">{{ __('portal.whitelist.title') }}</a>
                        </div>
                    </details>
                    @if (auth()->user()->isAdmin())
                        <details class="gp-nav-group relative">
                            <summary class="cursor-pointer select-none">{{ __('portal.nav.system') }}</summary>
                            <div class="gp-nav-menu absolute left-0 z-20 mt-1 min-w-40 rounded bg-(--gp-header-bg) py-1 shadow-lg ring-1 ring-white/20">
                                <a href="{{ route('admin.sites.index') }}" class="block px-3 py-1 hover:underline">{{ __('portal.sites.title') }}</a>
                                <a href="{{ route('admin.terms') }}" class="block px-3 py-1 hover:underline">{{ __('portal.terms.title') }}</a>
                                <a href="{{ route('admin.settings') }}" class="block px-3 py-1 hover:underline">{{ __('portal.admin.settings') }}</a>
                                <a href="{{ route('admin.users.index') }}" class="block px-3 py-1 hover:underline">{{ __('portal.users.title') }}</a>
                                <a href="{{ route('admin.audit') }}" class="block px-3 py-1 hover:underline">{{ __('portal.audit.title') }}</a>
                                <a href="{{ route('admin.certificates.index') }}" class="block px-3 py-1 hover:underline">{{ __('portal.certificates.title') }}</a>
                                <a href="{{ route('admin.backups.index') }}" class="block px-3 py-1 hover:underline">{{ __('portal.backups.title') }}</a>
                                <a href="{{ route('admin.federation') }}" class="block px-3 py-1 hover:underline">{{ __('portal.federation.title') }}</a>
                            </div>
                        </details>
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
    <script>
    (function () {
        var d = document;
        // Close open nav dropdowns on outside click / Escape.
        d.addEventListener('click', function (e) {
            d.querySelectorAll('.gp-nav-group[open]').forEach(function (g) { if (!g.contains(e.target)) g.removeAttribute('open'); });
        });
        d.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') d.querySelectorAll('.gp-nav-group[open]').forEach(function (g) { g.removeAttribute('open'); });
        });
        d.querySelectorAll('.gp-nav-group').forEach(function (g) {
            g.addEventListener('toggle', function () {
                if (g.open) d.querySelectorAll('.gp-nav-group[open]').forEach(function (o) { if (o !== g) o.removeAttribute('open'); });
            });
        });

        // Keep scroll position across a form POST + redirect back to the same page.
        var skey = 'gp-scroll:' + location.pathname;
        try {
            var saved = sessionStorage.getItem(skey);
            if (saved !== null) { sessionStorage.removeItem(skey); window.scrollTo(0, parseInt(saved, 10) || 0); }
            d.addEventListener('submit', function (e) {
                var f = e.target;
                if (f && f.method && f.method.toLowerCase() === 'post') sessionStorage.setItem(skey, String(window.scrollY));
            });
        } catch (e) {}

        // Collapsible sections: direct-child h2 of a section/card inside <main>; needs >= 3 per page.
        var heads = Array.prototype.filter.call(d.querySelectorAll('main h2'), function (h) {
            return h.parentElement && h.parentElement.tagName !== 'MAIN' && !h.closest('details');
        });
        if (heads.length >= 3) {
            var pre = 'gp-collapse:' + location.pathname + ':';
            heads.forEach(function (h, i) {
                var body = [];
                for (var n = h.nextElementSibling; n; n = n.nextElementSibling) body.push(n);
                if (!body.length) return;
                var key = pre + i, open = true;
                try { open = localStorage.getItem(key) !== '0'; } catch (e) {}
                h.setAttribute('role', 'button');
                h.tabIndex = 0;
                h.style.cursor = 'pointer';
                var apply = function () {
                    h.setAttribute('aria-expanded', open ? 'true' : 'false');
                    body.forEach(function (n) { n.style.display = open ? '' : 'none'; });
                };
                var toggle = function () {
                    open = !open; apply();
                    try { localStorage.setItem(key, open ? '1' : '0'); } catch (e) {}
                };
                h.addEventListener('click', function (e) { if (!e.target.closest('a, button, input')) toggle(); });
                h.addEventListener('keydown', function (e) {
                    if ((e.key === 'Enter' || e.key === ' ') && e.target === h) { e.preventDefault(); toggle(); }
                });
                apply();
            });
        }
    })();
    </script>
</body>
</html>
