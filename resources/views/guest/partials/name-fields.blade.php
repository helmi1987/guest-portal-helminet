{{-- First and last name; prefilled from old input or $first / $last. --}}
<div class="gp-name-fields grid grid-cols-1 gap-3 min-[360px]:grid-cols-2">
    <label class="gp-label label">
        <span>{{ __('portal.guest.first_name') }}</span>
        <input name="first_name" value="{{ old('first_name', $first ?? null) }}" required maxlength="100" autocomplete="given-name"
               class="gp-input field text-base">
    </label>
    <label class="gp-label label">
        <span>{{ __('portal.guest.last_name') }}</span>
        <input name="last_name" value="{{ old('last_name', $last ?? null) }}" required maxlength="100" autocomplete="family-name"
               class="gp-input field text-base">
    </label>
</div>
