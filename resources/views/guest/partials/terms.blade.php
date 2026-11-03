{{-- Terms of use of the current version and the acceptance checkbox; every login form includes it. --}}
<input type="hidden" name="terms_version" value="{{ $terms?->id }}">
@if ($terms)
    <details class="gp-terms rounded border border-(--gp-divider) bg-[color-mix(in_srgb,var(--gp-text)_3%,transparent)] text-sm">
        <summary class="cursor-pointer px-3 py-2 font-medium">
            {{ __('portal.guest.terms_show') }}
            <span class="opacity-70">({{ $terms->isDefault() ? __('portal.guest.terms_default') : __('portal.guest.terms_version', ['version' => $terms->number]) }})</span>
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
