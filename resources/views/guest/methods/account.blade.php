{{-- Login with the user name and password of the site's directory (CONCEPT.md §7.2 E); the name comes from the directory. --}}
<form id="gp-login-form" method="POST" action="{{ $guestPreview?->action() ?? route('guest.account') }}" class="gp-form flex flex-col gap-4" @if ($guestPreview) novalidate @endif>
    @csrf
    {{ $guestPreview?->fields('status') }}
    <label class="gp-label label">
        <span>{{ __('portal.guest.account_username') }}</span>
        <input name="username" value="{{ old('username') }}" required maxlength="128" autocomplete="username" autocapitalize="none" spellcheck="false"
               class="gp-input gp-account-username field text-base">
    </label>
    <label class="gp-label label">
        <span>{{ __('portal.guest.account_password') }}</span>
        <input type="password" name="password" value="" required maxlength="256" autocomplete="current-password"
               class="gp-input gp-account-password field text-base">
    </label>
    @include('guest.partials.terms')
    @include('guest.partials.errors')
    <button type="submit" class="gp-button btn py-3 text-base">{{ __('portal.guest.connect') }}</button>
</form>
<div class="gp-hint gp-account-hint mt-4 text-sm opacity-80">
    <p>{{ __('portal.guest.account_hint') }}</p>
</div>
