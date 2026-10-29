<x-layouts.guest step="handoff">
    <section class="gp-card card">
        <h1 class="gp-card-title mb-2 text-xl font-semibold">{{ __('portal.guest.connecting') }}</h1>
        {{-- One-time credentials (valid ~60 s) are submitted to the router's login URL. --}}
        <form id="handoff" method="POST" action="{{ $url }}" class="gp-form">
            @foreach ($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <noscript><p class="gp-hint mb-3 text-sm opacity-80">{{ __('portal.guest.noscript') }}</p></noscript>
            <button type="submit" class="gp-button btn w-full py-3 text-base">{{ __('portal.guest.continue') }}</button>
        </form>
    </section>
    <script>document.getElementById('handoff').submit();</script>
</x-layouts.guest>
