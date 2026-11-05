{{-- After the guest's logout (decision 2026-11-05): the session ends, the grant stays usable. --}}
<x-layouts.guest :title="__('portal.guest.logged_out_title')" step="logged-out">
    <section class="gp-card card" data-logged-out>
        <h1 class="gp-card-title mb-2 text-xl font-semibold sm:text-2xl">{{ __('portal.guest.logged_out_title') }}</h1>
        <p class="gp-intro opacity-80">{{ $timeout ? __('portal.guest.logged_out_timeout') : __('portal.guest.logged_out') }}</p>
        @if ($signInUrl)
            <p class="mt-4"><a href="{{ $guestPreview?->url('chooser') ?? $signInUrl }}" class="gp-button btn" data-sign-in-again>{{ __('portal.guest.access_ended.sign_in_again') }}</a></p>
        @endif
    </section>
</x-layouts.guest>
