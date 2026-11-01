<x-layouts.admin :title="__('portal.toolbox.title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.toolbox.title') }}</h1>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.toolbox.help') }}</p>

    <ul class="grid gap-4 md:grid-cols-2">
        <li class="card">
            <a href="{{ route('admin.toolbox.wifi-qr') }}" class="text-lg font-semibold underline">{{ __('portal.toolbox.wifi_qr.title') }}</a>
            <p class="mt-1 text-sm text-slate-600">{{ __('portal.toolbox.wifi_qr.help') }}</p>
        </li>
    </ul>
</x-layouts.admin>
