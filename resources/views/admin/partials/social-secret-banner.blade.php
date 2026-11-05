{{-- Social login secrets that expire within 30 days or have expired (decision 2026-11-05, v0.31.0): admins only,
     on the dashboard and above the social login settings. $entries: provider => SecretExpiry::status(). --}}
@if ($entries !== [])
    <section class="card {{ $class ?? 'mt-6' }} border-2 border-amber-300" data-social-secret-banner>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.social_secret.banner.title') }}</h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.social_secret.banner.intro') }}</p>
        <ul class="space-y-1 text-sm">
            @foreach ($entries as $provider => $status)
                <li data-social-secret-expiring="{{ $provider }}">
                    <span class="font-medium">{{ \App\Auth\Social\SocialLogin::name($provider) }}</span>:
                    @include('admin.partials.social-secret-status', ['status' => $status])
                    <a href="{{ route('admin.settings') }}#social-{{ $provider }}" class="underline">{{ __('portal.social_secret.banner.show') }}</a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
