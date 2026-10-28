{{-- Fields editable at creation and later. --}}
<label class="label">{{ __('portal.grants.label') }}
    <input name="label" value="{{ old('label', $grant?->label) }}" maxlength="100" class="field"></label>
<label class="label">{{ __('portal.grants.rate_limit') }}
    <input name="rate_limit" value="{{ old('rate_limit', $grant?->rate_limit) }}" placeholder="5M/10M" class="field">
    <span class="text-xs text-slate-500">{{ __('portal.grants.rate_limit_help') }}</span></label>
<label class="label">{{ __('portal.grants.valid_from') }}
    <input type="datetime-local" name="valid_from" value="{{ old('valid_from', $grant?->valid_from?->format('Y-m-d\TH:i')) }}" class="field"></label>
<label class="label">{{ __('portal.grants.redeem_until') }}
    <input type="datetime-local" name="redeem_until" value="{{ old('redeem_until', $grant?->redeem_until?->format('Y-m-d\TH:i')) }}" class="field"></label>
<label class="label">{{ __('portal.grants.max_extensions') }}
    <input type="number" min="0" name="max_extensions" value="{{ old('max_extensions', $grant?->max_extensions) }}" class="field"></label>
<fieldset class="label">
    <legend class="mb-1">{{ __('portal.grants.sites') }} <span class="text-xs text-slate-500">({{ __('portal.grants.sites_help') }})</span></legend>
    @foreach ($sites as $id => $name)
        <label class="flex items-center gap-2"><input type="checkbox" name="site_ids[]" value="{{ $id }}"
            @checked(in_array($id, old('site_ids', $grant?->siteIds() ?? []), true))> {{ $name }}</label>
    @endforeach
</fieldset>
<label class="label md:col-span-2">{{ __('portal.grants.note') }}
    <textarea name="note" rows="2" maxlength="1000" class="field">{{ old('note', $grant?->note) }}</textarea></label>
