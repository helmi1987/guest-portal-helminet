@props(['code', 'title' => null])
{{-- Command block with copy button (Alpine is loaded with Livewire on admin pages). --}}
<div x-data="{ copied: false }" class="mb-4">
    <div class="mb-1 flex items-center justify-between gap-2">
        <span class="text-sm font-medium">{{ $title }}</span>
        <button type="button" class="btn-light"
            x-on:click="
                const done = () => { copied = true; setTimeout(() => copied = false, 1500) };
                const fallback = () => { const r = document.createRange(); r.selectNodeContents($refs.code);
                    const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('copy'); done() };
                navigator.clipboard ? navigator.clipboard.writeText($refs.code.textContent).then(done, fallback) : fallback()"
            x-text="copied ? @js(__('portal.setup.copied')) : @js(__('portal.setup.copy'))">{{ __('portal.setup.copy') }}</button>
    </div>
    <pre x-ref="code" class="overflow-x-auto rounded bg-slate-900 p-3 text-xs text-slate-100">{{ $code }}</pre>
</div>
