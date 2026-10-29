<x-layouts.guest step="approval">
    {{-- Plain reload (no JS needed in captive browsers); logs in automatically once approved. --}}
    <x-slot:head><meta http-equiv="refresh" content="3"></x-slot:head>
    <section class="gp-card card">
        <h1 class="gp-card-title mb-2 text-xl font-semibold">{{ __('portal.guest.approval_waiting_title') }}</h1>
        <p class="gp-intro opacity-80">{{ __('portal.guest.approval_waiting') }}</p>
    </section>
</x-layouts.guest>
