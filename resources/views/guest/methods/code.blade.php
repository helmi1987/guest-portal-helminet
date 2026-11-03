{{-- Login with an access code or the site code (CONCEPT.md §7.2 A, §8). --}}
<form id="gp-login-form" method="POST" action="{{ route('guest.redeem') }}" class="gp-form flex flex-col gap-4">
    @csrf
    @include('guest.partials.name-fields')
    <label class="gp-label label">
        <span>{{ __('portal.guest.code') }}</span>
        <input name="code" value="{{ old('code', $prefillCode ?? null) }}" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code"
               class="gp-input gp-code-input field text-center text-2xl tracking-widest">
    </label>
    @include('guest.partials.terms')
    @include('guest.partials.errors')
    <button type="submit" class="gp-button btn py-3 text-base">{{ __('portal.guest.connect') }}</button>
</form>
