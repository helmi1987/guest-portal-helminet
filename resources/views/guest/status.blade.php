<x-layouts.guest step="status">
    <section class="gp-card card">
        <h1 class="gp-card-title gp-success mb-2 text-xl font-semibold text-emerald-700 sm:text-2xl">{{ __('portal.guest.online') }}</h1>
        @if ($remaining)
            <p class="gp-remaining mb-2">{{ __('portal.guest.remaining') }}: <strong>{{ $remaining }}</strong></p>
        @endif
        <p class="gp-hint opacity-80">{{ __('portal.guest.online_hint') }}</p>
    </section>
</x-layouts.guest>
