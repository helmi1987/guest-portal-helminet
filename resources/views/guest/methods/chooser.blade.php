{{-- Method chooser (decision 2026-11-03): one entry per available method, plain links (no JS); the social entry is one
     link with the provider logos inside (v0.31.1).
     Admin preview ($guestPreview): the links lead to the preview screens. --}}
<nav id="gp-methods" class="gp-methods flex flex-col gap-3" aria-label="{{ __('portal.guest.intros.chooser') }}">
    @foreach ($methods as $option)
        @if ($option === 'social')
            {{-- The whole social box is one button to the social page (decision 2026-11-06, v0.31.1): label plus the round
                 provider logos (decision 2026-11-05, v0.30.2) as decoration, like the other method buttons. --}}
            <a href="{{ $guestPreview?->url('social') ?? route('guest.portal', ['method' => 'social']) }}"
               @class(['gp-button gp-method gp-method-social flex flex-col items-center gap-2 py-3 text-center text-base', 'btn' => $loop->first, 'gp-button-secondary btn-light' => ! $loop->first])>
                <span>{{ __('portal.guest.choose.social') }}</span>
                <span class="gp-social-logos flex flex-wrap justify-center gap-3">
                    @foreach ($socialProviders as $provider)
                        <span title="{{ __('portal.guest.social_with', ['provider' => \App\Auth\Social\SocialLogin::name($provider)]) }}"
                              class="gp-social-{{ $provider }} inline-flex rounded-full ring-1 ring-(--gp-border)">@include('guest.partials.social-logo', ['provider' => $provider, 'size' => 'size-12'])</span>
                    @endforeach
                </span>
            </a>
        @else
            <a href="{{ $guestPreview?->url($option) ?? route('guest.portal', ['method' => $option]) }}"
               @class(['gp-button gp-method py-3 text-center text-base', 'gp-method-'.$option, 'btn' => $loop->first, 'gp-button-secondary btn-light' => ! $loop->first])>{{ __('portal.guest.choose.'.$option) }}</a>
        @endif
    @endforeach
</nav>
