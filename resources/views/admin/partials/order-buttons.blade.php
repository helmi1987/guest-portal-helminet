{{-- Up/down buttons of a display order row (decision 2026-11-04), see admin/settings-login.blade.php.
     Parameters: $key (item key), $label (item name for the screen reader label), $first, $last. --}}
<span class="flex shrink-0 gap-1">
    <button type="submit" name="move" value="{{ $key }}:up" data-order-move="up" class="btn-light px-2 py-0.5 leading-none disabled:opacity-40"
        title="{{ __('portal.settings.order.up') }}" aria-label="{{ __('portal.settings.order.up_label', ['item' => $label]) }}" @disabled($first)>&#9650;</button>
    <button type="submit" name="move" value="{{ $key }}:down" data-order-move="down" class="btn-light px-2 py-0.5 leading-none disabled:opacity-40"
        title="{{ __('portal.settings.order.down') }}" aria-label="{{ __('portal.settings.order.down_label', ['item' => $label]) }}" @disabled($last)>&#9660;</button>
</span>
