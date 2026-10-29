<x-layouts.admin :title="$entry->exists ? $entry->mac : __('portal.whitelist.create')">
    <h1 class="mb-4 text-2xl font-semibold">{{ $entry->exists ? __('portal.whitelist.title').' '.$entry->mac : __('portal.whitelist.create') }}</h1>

    <form method="POST" action="{{ $entry->exists ? route('admin.whitelist.update', $entry) : route('admin.whitelist.store') }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf
        @if ($entry->exists) @method('PUT') @endif
        <label class="label">MAC
            <input name="mac" value="{{ old('mac', $entry->mac) }}" required maxlength="17" placeholder="AA:BB:CC:DD:EE:FF" autocomplete="off" class="field font-mono"></label>
        <label class="label">{{ __('portal.whitelist.comment') }}
            <input name="comment" value="{{ old('comment', $entry->comment) }}" required maxlength="255" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.whitelist.comment_help') }}</span></label>
        <label class="label">{{ __('portal.whitelist.expires_at') }}
            <input type="datetime-local" name="expires_at" value="{{ old('expires_at', \App\Support\LocalTime::format($entry->expires_at, 'Y-m-d\TH:i')) }}" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.whitelist.expires_help') }}</span></label>
        <label class="flex items-center gap-2 text-sm"><input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" @checked(old('active', $entry->active ?? true))> {{ __('portal.whitelist.active') }}</label>
        <fieldset class="label">
            <legend class="mb-1">{{ __('portal.grants.sites') }} <span class="text-xs text-slate-500">({{ __('portal.grants.sites_help') }})</span></legend>
            @foreach ($sites as $id => $name)
                <label class="flex items-center gap-2"><input type="checkbox" name="site_ids[]" value="{{ $id }}"
                    @checked(in_array($id, old('site_ids', $selectedSites), true))> {{ $name }}</label>
            @endforeach
        </fieldset>
        <div class="md:col-span-2 flex gap-2">
            <button class="btn">{{ __('portal.save') }}</button>
            <a href="{{ route('admin.whitelist.index') }}" class="btn-light">{{ __('portal.back') }}</a>
        </div>
    </form>

    @if ($entry->exists)
        <section class="card mb-6">
            <h2 class="mb-2 text-lg font-semibold">{{ __('portal.grants.sessions') }}</h2>
            <table class="data-table">
                <thead><tr><th>{{ __('portal.guests.site') }}</th><th>IP</th><th>{{ __('portal.grants.started_at') }}</th><th>{{ __('portal.grants.stopped_at') }}</th></tr></thead>
                <tbody>
                    @forelse ($entry->sessions as $session)
                        <tr><td>{{ $sites[$session->site_id] ?? '–' }}</td><td>{{ $session->ip ?? '–' }}</td>
                            <td>{{ \App\Support\LocalTime::format($session->started_at, 'Y-m-d H:i') }}</td><td>{{ \App\Support\LocalTime::format($session->stopped_at, 'Y-m-d H:i') ?? __('portal.grants.online') }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <form method="POST" action="{{ route('admin.whitelist.destroy', $entry) }}" class="card" onsubmit="return confirm(@js(__('portal.confirm')))">
            @csrf @method('DELETE')
            <button class="btn-danger">{{ __('portal.delete') }}</button>
        </form>
    @endif
</x-layouts.admin>
