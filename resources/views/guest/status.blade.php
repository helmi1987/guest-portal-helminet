<x-layouts.guest>
    <section class="card">
        <h1 class="mb-2 text-xl font-semibold text-emerald-700 sm:text-2xl">{{ __('portal.guest.online') }}</h1>
        @if ($remaining)
            <p class="mb-2">{{ __('portal.guest.remaining') }}: <strong>{{ $remaining }}</strong></p>
        @endif
        <p class="text-slate-600">{{ __('portal.guest.online_hint') }}</p>
    </section>
</x-layouts.guest>
