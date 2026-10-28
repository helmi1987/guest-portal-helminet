<x-layouts.admin :title="__('portal.admin.dashboard')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.admin.dashboard') }}</h1>
    <p class="text-slate-600">{{ __('portal.admin.welcome', ['name' => auth()->user()->name]) }}</p>
    <p class="mt-2 text-sm text-slate-500">{{ __('portal.admin.not_ready') }}</p>
</x-layouts.admin>
