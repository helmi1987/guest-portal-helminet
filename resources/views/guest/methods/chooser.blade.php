{{-- Method chooser (decision 2026-11-03): one entry per available method, plain links (no JS).
     Admin preview ($guestPreview): the links lead to the preview screens. --}}
<nav id="gp-methods" class="gp-methods flex flex-col gap-3" aria-label="{{ __('portal.guest.intros.chooser') }}">
    @foreach ($methods as $option)
        @if ($option === 'social')
            <div class="gp-method gp-method-social flex flex-col gap-2 rounded border border-(--gp-divider) p-3">
                <p class="text-sm font-medium">{{ __('portal.guest.choose.social') }}</p>
                @foreach ($socialProviders as $provider)
                    <a href="{{ $guestPreview?->url('social') ?? route('guest.portal', ['method' => 'social']) }}"
                       class="gp-button gp-button-secondary gp-social-button gp-social-{{ $provider }} btn-light py-3 text-center text-base">{{ __('portal.guest.social_with', ['provider' => \App\Auth\Social\SocialLogin::name($provider)]) }}</a>
                @endforeach
            </div>
        @else
            <a href="{{ $guestPreview?->url($option) ?? route('guest.portal', ['method' => $option]) }}"
               @class(['gp-button gp-method py-3 text-center text-base', 'gp-method-'.$option, 'btn' => $loop->first, 'gp-button-secondary btn-light' => ! $loop->first])>{{ __('portal.guest.choose.'.$option) }}</a>
        @endif
    @endforeach
</nav>
