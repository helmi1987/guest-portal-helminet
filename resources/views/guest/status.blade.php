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

    @if ($usage ?? null)
        {{-- Usage of the open session of this device (decision 2026-11-05): as of the last accounting update. --}}
        <section class="gp-card gp-usage card" data-usage>
            <h2 class="gp-card-title mb-2 text-lg font-semibold">{{ __('portal.guest.usage_title') }}</h2>
            <dl class="gp-usage-list grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                <dt class="opacity-80">{{ __('portal.guest.usage_connected') }}</dt>
                <dd class="font-medium" data-usage-connected>{{ $usage['connected'] }}</dd>
                @if ($usage['down'] !== null)
                    <dt class="opacity-80">{{ __('portal.guest.usage_data') }}</dt>
                    <dd class="font-medium" data-usage-data>↓ {{ $usage['down'] }} · ↑ {{ $usage['up'] }}</dd>
                @endif
            </dl>
            @if ($usage['as_of'] !== null)
                <p class="gp-hint mt-2 text-sm opacity-80" data-usage-as-of>{{ __('portal.guest.usage_as_of', ['time' => $usage['as_of']]) }} {{ __('portal.guest.usage_hint', ['minutes' => $usage['minutes']]) }}</p>
            @endif
        </section>
    @endif

    <section class="gp-card gp-status-actions card flex flex-wrap items-center gap-3">
        @if ($logoutUrl ?? null)
            <form method="POST" action="{{ $guestPreview?->action() ?? $logoutUrl }}" class="gp-logout-form">
                @csrf {{ $guestPreview?->fields('logged_out') }}
                <button type="submit" class="gp-button gp-logout btn">{{ __('portal.guest.logout') }}</button>
            </form>
        @endif
        @unless ($controller ?? false)
            <a href="{{ $guestPreview?->url('status') ?? request()->fullUrl() }}" class="gp-button gp-button-secondary gp-refresh btn-light">{{ __('portal.guest.refresh') }}</a>
        @endunless
    </section>
</x-layouts.guest>
