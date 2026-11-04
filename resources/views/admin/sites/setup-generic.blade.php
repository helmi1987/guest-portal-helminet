{{-- Setup page of a generic RADIUS captive-portal site (experimental, decision 2026-11-04, v0.28.0): checklist,
     mapping summary and the simulation link instead of the RouterOS blocks. --}}
<x-layouts.admin :title="__('portal.setup.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.setup.title') }}: {{ $site->name }}</h1>
    <p class="mb-4 rounded bg-amber-100 px-4 py-2 text-sm text-amber-900" data-generic-experimental>{{ __('portal.generic.experimental') }}</p>
    <section class="card mb-6" data-generic-checklist>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.generic.checklist_title') }}</h2>
        <p class="mb-2 text-sm text-slate-600">{{ __('portal.generic.checklist_help') }}</p>
        <pre class="overflow-x-auto rounded bg-slate-900 p-4 text-xs text-slate-100">{{ $checklist }}</pre>
    </section>
    <section class="card mb-6" data-generic-mapping>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.generic.mapping_title') }}</h2>
        <p class="mb-2 text-sm">{{ __('portal.generic.fields.preset') }}: {{ __('portal.generic.presets.'.$mapping['preset'].'.label') }}</p>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
            @foreach (['param_mac', 'param_ip', 'param_login_url', 'login_url', 'param_orig', 'method', 'field_username', 'field_password', 'field_success', 'password_encoding'] as $field)
                <dt>{{ __('portal.generic.fields.'.$field) }}</dt><dd class="break-all font-mono">{{ $mapping[$field] !== '' ? $mapping[$field] : '–' }}</dd>
            @endforeach
            <dt>{{ __('portal.generic.fields.extra_fields') }}</dt><dd class="whitespace-pre font-mono">{{ $mapping['extra_fields'] !== '' ? $mapping['extra_fields'] : '–' }}</dd>
            <dt>{{ __('portal.generic.fields.disconnect_attrs') }}</dt><dd class="font-mono">{{ implode(', ', $mapping['disconnect_attrs']) ?: '–' }}</dd>
        </dl>
    </section>
    <section class="card mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.sites.generic.simulate', $site) }}" class="btn" data-generic-simulate>{{ __('portal.generic.simulate') }}</a>
        <a href="{{ route('admin.sites.edit', $site) }}#generic" class="btn-light">{{ __('portal.generic.settings_link') }}</a>
        @foreach ($site->nasClients as $router)
            <a href="{{ route('admin.sites.routers.config', [$site, $router]) }}" class="btn-light">{{ __('portal.sites.config') }}: {{ $router->name }}</a>
        @endforeach
        <span class="text-xs text-slate-500">{{ __('portal.generic.limits') }}</span>
    </section>
</x-layouts.admin>
