<x-layouts.guest>
    {{-- Plain reload (no JS needed in captive browsers); logs in automatically once approved. --}}
    <x-slot:head><meta http-equiv="refresh" content="3"></x-slot:head>
    <section class="card">
        <h1 class="mb-2 text-xl font-semibold">{{ __('portal.guest.approval_waiting_title') }}</h1>
        <p class="text-slate-600">{{ __('portal.guest.approval_waiting') }}</p>
    </section>
</x-layouts.guest>
