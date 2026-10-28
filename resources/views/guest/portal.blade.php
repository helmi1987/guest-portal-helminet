<x-layouts.guest>
    <section class="rounded-lg bg-white p-6 shadow">
        <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.guest.heading') }}</h1>
        @if (! $ready)
            <p class="text-slate-600">{{ __('portal.guest.errors.no_hotspot') }}</p>
        @else
            <p class="mb-4 text-slate-600">{{ __('portal.guest.code_intro') }}</p>
            <form method="POST" action="{{ route('guest.redeem') }}" class="flex flex-col gap-4">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1">
                        <span class="text-sm">{{ __('portal.guest.first_name') }}</span>
                        <input name="first_name" value="{{ old('first_name') }}" required maxlength="100" autocomplete="given-name"
                               class="rounded border border-slate-300 px-3 py-2">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-sm">{{ __('portal.guest.last_name') }}</span>
                        <input name="last_name" value="{{ old('last_name') }}" required maxlength="100" autocomplete="family-name"
                               class="rounded border border-slate-300 px-3 py-2">
                    </label>
                </div>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('portal.guest.code') }}</span>
                    <input name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code"
                           class="rounded border border-slate-300 px-3 py-2 text-center text-2xl tracking-widest">
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="terms" value="1" required class="mt-1"> {{ __('portal.guest.terms') }}
                </label>
                @if ($errors->any())
                    <ul class="text-sm text-red-600">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                @endif
                <button type="submit" class="rounded bg-slate-900 px-4 py-2 font-medium text-white">{{ __('portal.guest.connect') }}</button>
            </form>
        @endif
    </section>
</x-layouts.guest>
