{{-- Login page frame (CONCEPT.md §7.1): the method chooser, or the page of one method (guest.methods.*). --}}
@php($page = $method ?? 'chooser')
<x-layouts.guest step="portal" :variant="$page">
    <section class="gp-card card">
        <h1 class="gp-card-title mb-1 text-xl font-semibold sm:text-2xl">{{ __('portal.guest.heading') }}</h1>
        <p class="gp-intro mb-4 opacity-80">{{ __('portal.guest.intros.'.$page) }}</p>

        @if ($endedNotice ?? null)
            <div id="gp-access-ended" class="gp-notice gp-access-ended mb-4 flex flex-wrap items-center justify-between gap-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="status">
                <p class="gp-access-ended-text font-medium">{{ __('portal.guest.access_ended.'.$endedNotice) }}</p>
                <a href="#{{ $method ? 'gp-login-form' : 'gp-methods' }}" id="gp-sign-in-again" class="gp-access-ended-button btn-light">{{ __('portal.guest.access_ended.sign_in_again') }}</a>
            </div>
            <script>
                document.getElementById('gp-sign-in-again').addEventListener('click', function (e) {
                    e.preventDefault();
                    document.getElementById('gp-access-ended').hidden = true;
                    var target = document.getElementById(this.getAttribute('href').slice(1));
                    if (!target) return;
                    target.scrollIntoView({behavior: 'smooth', block: 'start'});
                    var input = target.querySelector('input[name="first_name"], input[name="terms"], a');
                    if (input) input.focus({preventScroll: true});
                });
            </script>
        @endif

        @if ($routerFailed ?? false)
            <p class="gp-notice mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="status">
                {{ __('portal.guest.router_failed_notice') }}
            </p>
        @endif

        @if ($limitedMode ?? false)
            {{-- Federation limited mode: the connection to the hub is interrupted. --}}
            <p class="gp-notice gp-limited-mode mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">{{ __('portal.guest.limited_mode') }}</p>
        @endif

        @include('guest.methods.'.$page)

        @if ($method !== null && count($methods) > 1)
            <p class="gp-other-methods mt-4 border-t border-(--gp-divider) pt-4 text-sm">
                <a href="{{ route('guest.portal') }}" class="gp-link underline">{{ __('portal.guest.other_methods') }}</a>
            </p>
        @endif
    </section>
</x-layouts.guest>
