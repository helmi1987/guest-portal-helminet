{{-- Social login without a complete name (CONCEPT.md §7.2 D): the guest completes it, then the login continues. --}}
<x-layouts.guest step="social-name">
    <section class="gp-card card">
        <h1 class="gp-card-title mb-1 text-xl font-semibold sm:text-2xl">{{ __('portal.guest.social_name_title') }}</h1>
        <p class="gp-intro mb-4 opacity-80">{{ __('portal.guest.social_name_intro', ['provider' => $provider]) }}</p>
        <form method="POST" action="{{ $guestPreview?->action() ?? route('guest.social.name.store') }}" class="gp-form flex flex-col gap-4" @if ($guestPreview) novalidate @endif>
            @csrf
            {{ $guestPreview?->fields('status') }}
            @include('guest.partials.name-fields')
            @include('guest.partials.errors')
            <button type="submit" class="gp-button btn py-3 text-base">{{ __('portal.guest.continue') }}</button>
        </form>
    </section>
</x-layouts.guest>
