<x-layouts.guest step="portal">
    <section class="gp-card card">
        <h1 class="gp-card-title mb-1 text-xl font-semibold sm:text-2xl">{{ __('portal.guest.heading') }}</h1>
        <p class="gp-intro mb-4 opacity-80">{{ __('portal.guest.code_intro') }}</p>

        @if ($endedNotice ?? null)
            <div id="gp-access-ended" class="gp-notice gp-access-ended mb-4 flex flex-wrap items-center justify-between gap-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="status">
                <p class="gp-access-ended-text font-medium">{{ __('portal.guest.access_ended.'.$endedNotice) }}</p>
                <a href="#gp-login-form" id="gp-sign-in-again" class="gp-access-ended-button btn-light">{{ __('portal.guest.access_ended.sign_in_again') }}</a>
            </div>
            <script>
                document.getElementById('gp-sign-in-again').addEventListener('click', function (e) {
                    e.preventDefault();
                    document.getElementById('gp-access-ended').hidden = true;
                    var form = document.getElementById('gp-login-form');
                    form.scrollIntoView({behavior: 'smooth', block: 'start'});
                    var input = form.querySelector('input[name="first_name"]');
                    if (input) input.focus({preventScroll: true});
                });
            </script>
        @endif

        @if ($routerFailed)
            <p class="gp-notice mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="status">
                {{ __('portal.guest.router_failed_notice') }}
            </p>
        @endif

        <form id="gp-login-form" method="POST" action="{{ route('guest.redeem') }}" class="gp-form flex flex-col gap-4">
            @csrf
            <input type="hidden" name="terms_version" value="{{ $terms?->id }}">
            <div class="gp-name-fields grid grid-cols-1 gap-3 min-[360px]:grid-cols-2">
                <label class="gp-label label">
                    <span>{{ __('portal.guest.first_name') }}</span>
                    <input name="first_name" value="{{ old('first_name') }}" required maxlength="100" autocomplete="given-name"
                           class="gp-input field text-base">
                </label>
                <label class="gp-label label">
                    <span>{{ __('portal.guest.last_name') }}</span>
                    <input name="last_name" value="{{ old('last_name') }}" required maxlength="100" autocomplete="family-name"
                           class="gp-input field text-base">
                </label>
            </div>
            <label class="gp-label label">
                <span>{{ __('portal.guest.code') }}</span>
                <input name="code" value="{{ old('code', $prefillCode ?? null) }}" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code"
                       class="gp-input gp-code-input field text-center text-2xl tracking-widest">
            </label>

            @if ($terms)
                <details class="gp-terms rounded border border-(--gp-divider) bg-[color-mix(in_srgb,var(--gp-text)_3%,transparent)] text-sm">
                    <summary class="cursor-pointer px-3 py-2 font-medium">
                        {{ __('portal.guest.terms_show') }}
                        <span class="opacity-70">({{ __('portal.guest.terms_version', ['version' => $terms->number]) }})</span>
                    </summary>
                    <div class="gp-terms-text max-h-64 space-y-2 overflow-y-auto border-t border-(--gp-divider) px-3 py-2" lang="{{ $terms->locale }}">
                        @foreach ($terms->paragraphs() as $paragraph)
                            <p>{!! nl2br(e($paragraph)) !!}</p>
                        @endforeach
                    </div>
                </details>
            @endif
            <label class="gp-label gp-terms-accept flex items-start gap-3 text-sm">
                <input type="checkbox" name="terms" value="1" required class="gp-checkbox mt-0.5 size-5 shrink-0 accent-(--gp-accent)"> {{ __('portal.guest.terms') }}
            </label>

            @if ($limitedMode ?? false)
                {{-- Federation limited mode: the connection to the hub is interrupted. --}}
                <p class="gp-notice gp-limited-mode rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">{{ __('portal.guest.limited_mode') }}</p>
            @endif
            @if ($errors->any())
                <ul class="gp-error rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif
            <button type="submit" class="gp-button btn py-3 text-base">{{ __('portal.guest.connect') }}</button>
            @if ($smsEnabled)
                {{-- No access code: get one by SMS (name and terms are checked by the server). --}}
                <div class="gp-alt-login gp-alt-sms flex flex-col gap-2 border-t border-(--gp-divider) pt-4 text-sm">
                    <p class="gp-hint opacity-80">{{ __('portal.guest.sms_intro') }}</p>
                    <label class="gp-label label">
                        <span>{{ __('portal.guest.phone') }}</span>
                        <input name="phone" type="tel" value="{{ old('phone') }}" maxlength="30" autocomplete="tel"
                               placeholder="+41 79 000 00 00" class="gp-input field text-base">
                    </label>
                    <button type="submit" formaction="{{ route('guest.sms.request') }}" formnovalidate
                            class="gp-button gp-button-secondary btn-light w-full py-3 text-base">{{ __('portal.guest.sms_request') }}</button>
                </div>
            @endif
            @if ($approvalEnabled)
                {{-- No access code: ask an approver instead (name and terms are checked by the server). --}}
                <div class="gp-alt-login gp-alt-approval border-t border-(--gp-divider) pt-4 text-sm">
                    <p class="gp-hint mb-2 opacity-80">{{ __('portal.guest.approval_intro') }}</p>
                    <button type="submit" formaction="{{ route('guest.approval.request') }}" formnovalidate
                            class="gp-button gp-button-secondary btn-light w-full py-3 text-base">{{ __('portal.guest.approval_request') }}</button>
                </div>
            @endif
        </form>
    </section>
</x-layouts.guest>
