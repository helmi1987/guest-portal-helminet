<x-layouts.admin :title="__('portal.whitelist.title')">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('portal.whitelist.title') }}</h1>
        <a href="{{ route('admin.whitelist.create') }}" class="btn">{{ __('portal.whitelist.create') }}</a>
    </div>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.whitelist.help') }}</p>

    <form method="GET" action="{{ route('admin.whitelist.index') }}" class="card mb-6 flex flex-wrap items-end gap-4">
        <label class="label">{{ __('portal.whitelist.search') }}
            <input name="q" value="{{ $filters['q'] }}" maxlength="100" class="field"></label>
        <label class="label">{{ __('portal.guests.site') }}
            <select name="site" class="field">
                <option value="">{{ __('portal.all') }}</option>
                @foreach ($sites as $id => $name)
                    <option value="{{ $id }}" @selected($filters['site'] === $id)>{{ $name }}</option>
                @endforeach
            </select></label>
        <label class="label">{{ __('portal.whitelist.status') }}
            <select name="status" class="field">
                <option value="">{{ __('portal.all') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('portal.whitelist.statuses.'.$status) }}</option>
                @endforeach
            </select></label>
        <button class="btn">{{ __('portal.audit.filter') }}</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>MAC</th><th>{{ __('portal.whitelist.comment') }}</th><th>{{ __('portal.grants.sites') }}</th>
                <th>{{ __('portal.whitelist.expires_at') }}</th><th>{{ __('portal.whitelist.status') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($entries as $entry)
                    @php($online = $entry->sessions->first())
                    <tr>
                        <td class="font-mono"><a href="{{ route('admin.whitelist.edit', $entry) }}" class="underline">{{ $entry->mac }}</a></td>
                        <td>{{ $entry->comment }}<div class="text-xs text-slate-500">{{ $entry->creator?->name ?? '–' }} · {{ \App\Support\LocalTime::format($entry->created_at, 'Y-m-d H:i') }}</div></td>
                        <td class="text-xs">{{ $entry->siteLinks->map(fn ($l) => $sites[$l->site_id] ?? $l->site_id)->join(', ') ?: __('portal.all') }}</td>
                        <td class="text-xs">{{ \App\Support\LocalTime::format($entry->expires_at, 'Y-m-d H:i') ?? __('portal.whitelist.unlimited') }}</td>
                        <td class="text-xs">
                            {{ __('portal.whitelist.statuses.'.(! $entry->active ? 'inactive' : ($entry->isExpired() ? 'expired' : 'active'))) }}
                            @if ($online)<br><span class="font-semibold text-green-700">{{ __('portal.grants.online') }}</span> {{ $sites[$online->site_id] ?? '' }} · IP {{ $online->ip ?? '–' }}@endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.whitelist.toggle', $entry) }}">
                                @csrf <button class="btn-light text-xs">{{ $entry->active ? __('portal.whitelist.deactivate') : __('portal.whitelist.activate') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($entries->hasPages())
            <nav class="mt-4 flex items-center justify-between text-sm">
                @if ($entries->onFirstPage()) <span></span> @else <a href="{{ $entries->previousPageUrl() }}" class="btn-light">‹</a> @endif
                <span>{{ __('portal.audit.page', ['page' => $entries->currentPage(), 'pages' => $entries->lastPage()]) }}</span>
                @if ($entries->hasMorePages()) <a href="{{ $entries->nextPageUrl() }}" class="btn-light">›</a> @else <span></span> @endif
            </nav>
        @endif
    </div>
</x-layouts.admin>
