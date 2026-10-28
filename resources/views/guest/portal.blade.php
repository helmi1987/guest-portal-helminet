<x-layouts.guest>
    <section class="card">
        <h1 class="mb-1 text-xl font-semibold sm:text-2xl">{{ __('portal.guest.heading') }}</h1>
        <p class="mb-4 text-slate-600">{{ __('portal.guest.code_intro') }}</p>

        @if ($routerFailed)
            <p class="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="status">
                {{ __('portal.guest.router_failed_notice') }}
            </p>
        @endif

        <form method="POST" action="{{ route('guest.redeem') }}" class="flex flex-col gap-4">
            @csrf
            <input type="hidden" name="terms_version" value="{{ $terms?->id }}">
            <div class="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2">
                <label class="label">
                    <span>{{ __('portal.guest.first_name') }}</span>
                    <input name="first_name" value="{{ old('first_name') }}" required maxlength="100" autocomplete="given-name"
                           class="field text-base">
                </label>
                <label class="label">
                    <span>{{ __('portal.guest.last_name') }}</span>
                    <input name="last_name" value="{{ old('last_name') }}" required maxlength="100" autocomplete="family-name"
                           class="field text-base">
                </label>
            </div>
            <label class="label">
                <span>{{ __('portal.guest.code') }}</span>
                <input name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code"
                       class="field text-center text-2xl tracking-widest">
            </label>

            @if ($terms)
                <details class="rounded border border-slate-200 bg-slate-50 text-sm">
                    <summary class="cursor-pointer px-3 py-2 font-medium">
                        {{ __('portal.guest.terms_show') }}
                        <span class="text-slate-500">({{ __('portal.guest.terms_version', ['version' => $terms->number]) }})</span>
                    </summary>
                    <div class="max-h-64 space-y-2 overflow-y-auto border-t border-slate-200 px-3 py-2 text-slate-700" lang="{{ $terms->locale }}">
                        @foreach ($terms->paragraphs() as $paragraph)
                            <p>{!! nl2br(e($paragraph)) !!}</p>
                        @endforeach
                    </div>
                </details>
            @endif
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" name="terms" value="1" required class="mt-0.5 size-5 shrink-0"> {{ __('portal.guest.terms') }}
            </label>

            @if ($errors->any())
                <ul class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif
            <button type="submit" class="btn py-3 text-base">{{ __('portal.guest.connect') }}</button>
        </form>
    </section>
</x-layouts.guest>
