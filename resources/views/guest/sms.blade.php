<x-layouts.guest>
    <section class="card">
        <h1 class="mb-1 text-xl font-semibold sm:text-2xl">{{ __('portal.guest.sms_title') }}</h1>
        <p class="mb-4 text-slate-600">{{ __('portal.guest.sms_sent', ['phone' => $phone, 'minutes' => config('portal.sms.ttl_minutes')]) }}</p>

        @if (session('status'))
            <p class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('guest.sms.verify') }}" class="flex flex-col gap-4">
            @csrf
            <label class="label">
                <span>{{ __('portal.guest.otp') }}</span>
                <input name="otp" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code" autofocus
                       class="field text-center text-2xl tracking-widest">
            </label>
            @if ($errors->any())
                <ul class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif
            <button type="submit" class="btn py-3 text-base">{{ __('portal.guest.connect') }}</button>
        </form>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 pt-4 text-sm">
            @if ($canResend)
                <form method="POST" action="{{ route('guest.sms.resend') }}">
                    @csrf <button type="submit" class="btn-light">{{ __('portal.guest.sms_resend') }}</button></form>
            @endif
            <a href="{{ route('guest.portal') }}" class="underline">{{ __('portal.guest.sms_change') }}</a>
        </div>
    </section>
</x-layouts.guest>
