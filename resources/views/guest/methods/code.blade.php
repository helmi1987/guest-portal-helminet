{{-- Login with an access code or the site code (CONCEPT.md §7.2 A, §8). --}}
<form id="gp-login-form" method="POST" action="{{ $guestPreview?->action() ?? route('guest.redeem') }}" class="gp-form flex flex-col gap-4" @if ($guestPreview) novalidate @endif>
    @csrf
    {{ $guestPreview?->fields('status') }}
    @include('guest.partials.name-fields')
    {{-- Email address: code login only (decision 2026-11-03, v0.24.1); SMS and approval keep name only. --}}
    <label class="gp-label gp-email-field label">
        <span>{{ __('portal.guest.email') }}</span>
        <input type="email" name="email" value="{{ old('email') }}" required maxlength="191" autocomplete="email" inputmode="email"
               class="gp-input field text-base">
    </label>
    <label class="gp-label label">
        <span>{{ __('portal.guest.code') }}</span>
        <input name="code" value="{{ old('code', $prefillCode ?? null) }}" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code"
               class="gp-input gp-code-input field text-center text-2xl tracking-widest">
    </label>
    @include('guest.partials.terms')
    @include('guest.partials.errors')
    <button type="submit" class="gp-button btn py-3 text-base">{{ __('portal.guest.connect') }}</button>
</form>
