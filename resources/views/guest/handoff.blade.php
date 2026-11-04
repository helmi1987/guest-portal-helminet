<x-layouts.guest step="handoff">
    @php($get = ($method ?? 'POST') === 'GET')
    <section class="gp-card card">
        <h1 class="gp-card-title mb-2 text-xl font-semibold">{{ __('portal.guest.connecting') }}</h1>
        {{-- One-time credentials (valid ~60 s) are submitted to the router's login URL. GET (generic RADIUS
             driver, decision 2026-11-04): navigation to the URL with the fields as query string. --}}
        @if ($get)
            <noscript><p class="gp-hint mb-3 text-sm opacity-80">{{ __('portal.guest.noscript') }}</p></noscript>
            <a id="handoff" href="{{ $url.(str_contains($url, '?') ? '&' : '?').http_build_query($fields, '', '&', PHP_QUERY_RFC3986) }}"
                class="gp-button btn block w-full py-3 text-center text-base" data-handoff-get>{{ __('portal.guest.continue') }}</a>
        @else
            <form id="handoff" method="POST" action="{{ $url }}" class="gp-form">
                @foreach ($fields as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <noscript><p class="gp-hint mb-3 text-sm opacity-80">{{ __('portal.guest.noscript') }}</p></noscript>
                <button type="submit" class="gp-button btn w-full py-3 text-base">{{ __('portal.guest.continue') }}</button>
            </form>
        @endif
    </section>
    @if ($get)
        <script>location.replace(document.getElementById('handoff').href);</script>
    @else
        <script>document.getElementById('handoff').submit();</script>
    @endif
</x-layouts.guest>
