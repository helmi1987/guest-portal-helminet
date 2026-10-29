<x-layouts.guest step="sms">
    <section class="gp-card card">
        <h1 class="gp-card-title mb-1 text-xl font-semibold sm:text-2xl">{{ __('portal.guest.sms_title') }}</h1>
        <p class="gp-intro mb-4 opacity-80">{{ __('portal.guest.sms_sent', ['phone' => $phone, 'minutes' => config('portal.sms.ttl_minutes')]) }}</p>

        @if (session('status'))
            <p class="gp-success mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('guest.sms.verify') }}" class="gp-form flex flex-col gap-4">
            @csrf
            <label class="gp-label label">
                <span>{{ __('portal.guest.otp') }}</span>
                <input name="otp" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code" autofocus
                       class="gp-input gp-code-input field text-center text-2xl tracking-widest">
            </label>
            @if ($errors->any())
                <ul class="gp-error rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif
            <button type="submit" class="gp-button btn py-3 text-base">{{ __('portal.guest.connect') }}</button>
        </form>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-(--gp-divider) pt-4 text-sm">
            @if ($canResend)
                <form method="POST" action="{{ route('guest.sms.resend') }}">
                    @csrf <button type="submit" class="gp-button gp-button-secondary btn-light">{{ __('portal.guest.sms_resend') }}</button></form>
            @endif
            <a href="{{ route('guest.portal') }}" class="gp-link underline">{{ __('portal.guest.sms_change') }}</a>
        </div>
    </section>
</x-layouts.guest>
