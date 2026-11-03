{{-- Login with an SMS code (CONCEPT.md §7.2 B): name, mobile number and terms, then the code page. --}}
<form id="gp-login-form" method="POST" action="{{ $guestPreview?->action() ?? route('guest.sms.request') }}" class="gp-form flex flex-col gap-4" @if ($guestPreview) novalidate @endif>
    @csrf
    {{ $guestPreview?->fields('sms_otp') }}
    @include('guest.partials.name-fields')
    <label class="gp-label label">
        <span>{{ __('portal.guest.phone') }}</span>
        <input name="phone" type="tel" value="{{ old('phone') }}" required maxlength="30" autocomplete="tel"
               placeholder="+41 79 000 00 00" class="gp-input field text-base">
    </label>
    @include('guest.partials.terms')
    @include('guest.partials.errors')
    <button type="submit" class="gp-button btn py-3 text-base">{{ __('portal.guest.sms_request') }}</button>
</form>
