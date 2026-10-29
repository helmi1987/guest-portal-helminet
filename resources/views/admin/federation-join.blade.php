<x-layouts.admin :title="__('portal.federation.join_title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.federation.join_title') }}</h1>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.federation.join_help') }}</p>

    @if ($notFresh)
        <p class="mb-4 rounded bg-red-100 px-4 py-2 text-sm text-red-800">{{ __('portal.federation.join_not_fresh', ['reason' => $notFresh]) }}</p>
    @endif
    @unless ($localPin)
        <p class="mb-4 rounded bg-amber-100 px-4 py-2 text-sm text-amber-800">{{ __('portal.federation.no_certificate') }}</p>
    @endunless

    <form method="POST" action="{{ route('admin.federation.join.store') }}" class="card grid gap-4 md:grid-cols-2"
          onsubmit="return confirm(@js(__('portal.federation.join_confirm')))">
        @csrf
        <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.federation.this_instance') }}</h2>
        <label class="label">{{ __('portal.federation.name') }}
            <input name="name" value="{{ old('name', $self->name) }}" required maxlength="100" class="field"></label>
        <label class="label">{{ __('portal.federation.federation_url') }}
            <input name="federation_url" value="{{ old('federation_url', $self->federation_url) }}" required placeholder="https://10.0.0.2:9443" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.federation.federation_url_help') }}</span></label>
        <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.federation.hub') }}</h2>
        <label class="label md:col-span-2">{{ __('portal.federation.join_url') }}
            <input name="url" value="{{ old('url') }}" required placeholder="https://10.0.0.1:9443" class="field"></label>
        <label class="label">{{ __('portal.federation.join_token') }}
            <input name="token" required maxlength="100" autocomplete="off" class="field font-mono"></label>
        <label class="label">{{ __('portal.federation.join_fingerprint') }}
            <input name="fingerprint" value="{{ old('fingerprint') }}" required placeholder="sha256//…" class="field font-mono">
            <span class="text-xs text-slate-500">{{ __('portal.federation.join_fingerprint_help') }}</span></label>
        <div class="md:col-span-2"><button class="btn" @disabled($notFresh)>{{ __('portal.federation.join_submit') }}</button></div>
    </form>
</x-layouts.admin>
