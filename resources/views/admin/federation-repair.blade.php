<x-layouts.admin :title="__('portal.federation.repair_title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.federation.repair_title') }}</h1>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.federation.repair_help') }}</p>
    @if ($removed)
        <p class="mb-4 rounded bg-red-100 px-4 py-2 text-sm text-red-800">{{ __('portal.federation.removed_here') }}</p>
    @endif

    <form method="POST" action="{{ route('admin.federation.repair.store') }}" class="card grid gap-4 md:grid-cols-2"
          onsubmit="return confirm(@js(__('portal.federation.repair_confirm')))">
        @csrf
        <label class="label md:col-span-2">{{ __('portal.federation.join_url') }}
            <input name="url" value="{{ old('url') }}" required placeholder="https://10.0.0.1:9443" class="field"></label>
        <label class="label">{{ __('portal.federation.join_token') }}
            <input name="token" required maxlength="100" autocomplete="off" class="field font-mono"></label>
        <label class="label">{{ __('portal.federation.join_fingerprint') }}
            <input name="fingerprint" value="{{ old('fingerprint') }}" required placeholder="sha256//…" class="field font-mono">
            <span class="text-xs text-slate-500">{{ __('portal.federation.join_fingerprint_help') }}</span></label>
        <div class="md:col-span-2"><button class="btn">{{ __('portal.federation.repair_submit') }}</button></div>
    </form>
</x-layouts.admin>
