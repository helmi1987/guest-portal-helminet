<x-layouts.guest :title="__('portal.guest.error_pages.'.$kind.'.title')">
    <section class="card">
        <h1 class="mb-2 text-xl font-semibold">{{ __('portal.guest.error_pages.'.$kind.'.title') }}</h1>
        <p class="text-slate-600">{{ __('portal.guest.error_pages.'.$kind.'.text') }}</p>
        @if (in_array($kind, ['expired', 'revoked', 'router_failed'], true) && session('portal'))
            <p class="mt-4"><a href="{{ route('guest.portal') }}" class="btn">{{ __('portal.guest.try_again') }}</a></p>
        @endif
    </section>
</x-layouts.guest>
