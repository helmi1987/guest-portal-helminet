{{-- Social login (CONCEPT.md §7.2 D): terms, then one button per provider; the name comes from the provider. --}}
<form id="gp-login-form" method="POST" action="{{ route('guest.social.start', $socialProviders[0] ?? 'google') }}" class="gp-form flex flex-col gap-4">
    @csrf
    @include('guest.partials.terms')
    @include('guest.partials.errors')
    <div class="gp-social-providers flex flex-col gap-3">
        @foreach ($socialProviders as $provider)
            <button type="submit" formaction="{{ route('guest.social.start', $provider) }}"
                    class="gp-button gp-social-button gp-social-{{ $provider }} btn py-3 text-base">{{ __('portal.guest.social_with', ['provider' => \App\Auth\Social\SocialLogin::name($provider)]) }}</button>
        @endforeach
    </div>
</form>
<div class="gp-hint gp-social-hint mt-4 text-sm opacity-80">
    <p>{{ __('portal.guest.social_hint') }}</p>
    <p class="mt-1 break-all"><a href="{{ $entryUrl }}" class="gp-link underline">{{ $entryUrl }}</a></p>
</div>
