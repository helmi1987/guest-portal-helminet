@props(['code', 'title' => null, 'disabled' => false])
{{-- Command block with copy button (Alpine is loaded with Livewire on admin pages). Disabled: greyed out
     (router state missing or stale, CONCEPT.md §9), not copyable, marked for assistive technology. --}}
<div x-data="{ copied: false }" @class(['mb-4', 'opacity-50 select-none' => $disabled]) @if ($disabled) aria-disabled="true" data-cli-disabled @endif>
    <div class="mb-1 flex items-center justify-between gap-2">
        <span class="text-sm font-medium">{{ $title }}</span>
        <button type="button" class="btn-light" @disabled($disabled) @if ($disabled) aria-disabled="true" title="{{ __('portal.router_api.cli_disabled_short') }}" @endif
            x-on:click="
                const done = () => { copied = true; setTimeout(() => copied = false, 1500) };
                const fallback = () => { const r = document.createRange(); r.selectNodeContents($refs.code);
                    const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('copy'); done() };
                navigator.clipboard ? navigator.clipboard.writeText($refs.code.textContent).then(done, fallback) : fallback()"
            x-text="copied ? @js(__('portal.setup.copied')) : @js(__('portal.setup.copy'))">{{ __('portal.setup.copy') }}</button>
    </div>
    <pre x-ref="code" @class(['overflow-x-auto rounded p-3 text-xs', 'bg-slate-900 text-slate-100' => ! $disabled, 'bg-slate-500 text-slate-200' => $disabled])>{{ $code }}</pre>
</div>
