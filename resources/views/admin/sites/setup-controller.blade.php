{{-- Setup page of a controller site (UniFi, experimental, decision 2026-11-04): checklist and check button
     instead of the RouterOS blocks. --}}
<x-layouts.admin :title="__('portal.setup.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.setup.title') }}: {{ $site->name }}</h1>
    <p class="mb-4 rounded bg-amber-100 px-4 py-2 text-sm text-amber-900" data-controller-experimental>{{ __('portal.controller.experimental') }}</p>
    <section class="card mb-6" data-controller-checklist>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.controller.checklist_title') }}</h2>
        <p class="mb-2 text-sm text-slate-600">{{ __('portal.controller.checklist_help') }}</p>
        <pre class="overflow-x-auto rounded bg-slate-900 p-4 text-xs text-slate-100">{{ $checklist }}</pre>
    </section>
    <section class="card mb-6 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('admin.sites.controller.check', $site) }}">@csrf
            <button class="btn">{{ __('portal.controller.check_button') }}</button></form>
        <a href="{{ route('admin.sites.edit', $site) }}#controller" class="btn-light">{{ __('portal.controller.settings_link') }}</a>
        <span class="text-xs text-slate-500">{{ __('portal.controller.limits') }}</span>
    </section>
</x-layouts.admin>
