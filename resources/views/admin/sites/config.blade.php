<x-layouts.admin :title="__('portal.sites.config')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.sites.config') }}: {{ $site->name }} / {{ $router->name }}</h1>
    <p class="mb-2 text-sm text-slate-600">{{ __('portal.sites.config_help') }}</p>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.sites.cert_help') }}</p>
    <div class="card"><x-admin.code :code="$script" :lines="$lines" :placeholders="$placeholders" /></div>
    <p class="mt-4 flex gap-2">
        <a href="{{ route('admin.sites.login-page', $site) }}" class="btn-light">{{ __('portal.sites.download_login') }}</a>
        <a href="{{ route('admin.sites.setup', $site) }}#step-radius" class="btn-light">{{ __('portal.setup.title') }}</a>
        <a href="{{ route('admin.sites.edit', $site) }}" class="btn-light">{{ __('portal.back') }}</a>
    </p>
</x-layouts.admin>
