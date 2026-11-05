{{-- Method chooser (decision 2026-11-03): one entry per available method, plain links (no JS).
     Admin preview ($guestPreview): the links lead to the preview screens. --}}
<nav id="gp-methods" class="gp-methods flex flex-col gap-3" aria-label="{{ __('portal.guest.intros.chooser') }}">
    @foreach ($methods as $option)
        @if ($option === 'social')
            <div class="gp-method gp-method-social flex flex-col gap-2 rounded border border-(--gp-divider) p-3">
                <p class="text-sm font-medium">{{ __('portal.guest.choose.social') }}</p>
                {{-- Round provider logos (decision 2026-11-05, v0.30.2): each leads to the social page, where the terms come first. --}}
                <div class="gp-social-logos flex flex-wrap gap-3">
                    @foreach ($socialProviders as $provider)
                        @php($label = __('portal.guest.social_with', ['provider' => \App\Auth\Social\SocialLogin::name($provider)]))
                        <a href="{{ $guestPreview?->url('social') ?? route('guest.portal', ['method' => 'social']) }}" title="{{ $label }}" aria-label="{{ $label }}"
                           class="gp-social-button gp-social-{{ $provider }} rounded-full ring-1 ring-(--gp-border) hover:ring-2 hover:ring-(--gp-accent) focus-visible:ring-2 focus-visible:ring-(--gp-accent) focus-visible:outline-none">@include('guest.partials.social-logo', ['provider' => $provider, 'size' => 'size-12'])</a>
                    @endforeach
                </div>
            </div>
        @else
            <a href="{{ $guestPreview?->url($option) ?? route('guest.portal', ['method' => $option]) }}"
               @class(['gp-button gp-method py-3 text-center text-base', 'gp-method-'.$option, 'btn' => $loop->first, 'gp-button-secondary btn-light' => ! $loop->first])>{{ __('portal.guest.choose.'.$option) }}</a>
        @endif
    @endforeach
</nav>
