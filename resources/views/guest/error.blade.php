<x-layouts.guest :title="__('portal.guest.error_pages.'.$kind.'.title')" step="error" :variant="$kind">
    <section class="gp-card card">
        <h1 class="gp-card-title mb-2 text-xl font-semibold">{{ __('portal.guest.error_pages.'.$kind.'.title') }}</h1>
        <p class="gp-intro opacity-80">{{ __('portal.guest.error_pages.'.$kind.'.text') }}</p>
        @if (in_array($kind, ['expired', 'revoked', 'router_failed', 'approval_denied', 'approval_expired', 'sms_failed', 'site_code_full'], true) && session('portal'))
            <p class="mt-4"><a href="{{ route('guest.portal') }}" class="gp-button btn">{{ __('portal.guest.try_again') }}</a></p>
        @endif
    </section>
</x-layouts.guest>
