<x-layouts.guest step="status">
    <section class="gp-card card">
        <h1 class="gp-card-title gp-success mb-2 text-xl font-semibold text-emerald-700 sm:text-2xl">{{ __('portal.guest.online') }}</h1>
        @if ($remaining)
            <p class="gp-remaining mb-2">{{ __('portal.guest.remaining') }}: <strong>{{ $remaining }}</strong></p>
        @endif
        @if ($controller ?? false)
            {{-- Controller site (UniFi, experimental): state as the controller reports it on this load. --}}
            <p class="gp-controller mb-2" data-controller-status="{{ ($controllerStatus['connected'] ?? false) && ($controllerStatus['authorized'] ?? false) ? 'online' : 'unknown' }}">
                {{ ($controllerStatus['connected'] ?? false) && ($controllerStatus['authorized'] ?? false) ? __('portal.guest.controller_connected') : __('portal.guest.controller_unknown') }}
                <a href="{{ request()->fullUrl() }}" class="underline">{{ __('portal.guest.controller_refresh') }}</a>
            </p>
            @if (! empty($origUrl))
                <p class="mb-2"><a href="{{ $origUrl }}" rel="noopener noreferrer" class="underline" data-orig-url>{{ __('portal.guest.controller_continue') }}</a></p>
            @endif
        @endif
        <p class="gp-hint opacity-80">{{ __('portal.guest.online_hint') }}</p>
    </section>
</x-layouts.guest>
