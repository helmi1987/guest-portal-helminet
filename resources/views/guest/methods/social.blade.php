{{-- Social login (CONCEPT.md §7.2 D): terms, then one button per provider with its logo; the name comes from the provider, if it reports one.
     The buttons look inactive until the terms checkbox is ticked (CSS only, app.css .gp-social-providers); the browser
     (required) and the server still enforce the terms. --}}
<form id="gp-login-form" method="POST" action="{{ $guestPreview?->action() ?? route('guest.social.start', $socialProviders[0] ?? 'google') }}" class="gp-form flex flex-col gap-4" @if ($guestPreview) novalidate @endif>
    @csrf
    {{ $guestPreview?->fields('status') }}
    @include('guest.partials.terms')
    @include('guest.partials.errors')
    <div class="gp-social-providers flex flex-col gap-3">
        @foreach ($socialProviders as $provider)
            <button type="submit" formaction="{{ $guestPreview?->action() ?? route('guest.social.start', $provider) }}"
                    class="gp-button gp-social-button gp-social-{{ $provider }} btn flex items-center justify-center gap-3 py-3 text-base">@include('guest.partials.social-logo', ['provider' => $provider])<span>{{ __('portal.guest.social_with', ['provider' => \App\Auth\Social\SocialLogin::name($provider)]) }}</span></button>
        @endforeach
    </div>
</form>
<div class="gp-hint gp-social-hint mt-4 text-sm opacity-80">
    <p>{{ __('portal.guest.social_hint') }}</p>
</div>
