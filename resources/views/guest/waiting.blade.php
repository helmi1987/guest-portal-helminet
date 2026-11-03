<x-layouts.guest step="waiting">
    <x-slot:head>@unless ($guestPreview)<meta http-equiv="refresh" content="2">@endunless</x-slot:head>
    <section class="gp-card card">
        <h1 class="gp-card-title mb-2 text-xl font-semibold">{{ __('portal.guest.connecting') }}</h1>
        <p class="gp-intro opacity-80">{{ __('portal.guest.waiting') }}</p>
    </section>
</x-layouts.guest>
