{{-- Ask the host for access (CONCEPT.md §7.2 C): name and terms, then the waiting page. --}}
<form id="gp-login-form" method="POST" action="{{ $guestPreview?->action() ?? route('guest.approval.request') }}" class="gp-form flex flex-col gap-4" @if ($guestPreview) novalidate @endif>
    @csrf
    {{ $guestPreview?->fields('approval_waiting') }}
    @include('guest.partials.name-fields')
    @include('guest.partials.terms')
    @include('guest.partials.errors')
    <button type="submit" class="gp-button btn py-3 text-base">{{ __('portal.guest.approval_request') }}</button>
</form>
