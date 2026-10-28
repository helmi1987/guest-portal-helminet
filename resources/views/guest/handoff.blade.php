<x-layouts.guest>
    <section class="card">
        <h1 class="mb-2 text-xl font-semibold">{{ __('portal.guest.connecting') }}</h1>
        {{-- One-time credentials (valid ~60 s) are submitted to the router's login URL. --}}
        <form id="handoff" method="POST" action="{{ $url }}">
            @foreach ($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <noscript><p class="mb-3 text-sm text-slate-600">{{ __('portal.guest.noscript') }}</p></noscript>
            <button type="submit" class="btn w-full py-3 text-base">{{ __('portal.guest.continue') }}</button>
        </form>
    </section>
    <script>document.getElementById('handoff').submit();</script>
</x-layouts.guest>
