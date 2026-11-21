<x-layouts.admin :title="__('portal.whitelist.title')">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('portal.whitelist.title') }}</h1>
        <a href="{{ route('admin.whitelist.create') }}" class="btn">{{ __('portal.whitelist.create') }}</a>
    </div>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.whitelist.help') }}</p>

    @include('admin.whitelist.partials.reauth')

    {{-- Learning window per site (decision 2026-11-21, v0.33.0): local to this instance, admin only --}}
    @if (auth()->user()->isAdmin())
    <section class="card mb-6" id="learning" data-learning>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.learning.title') }}</h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.learning.help') }}</p>
        @foreach ($windows as $window)
            <div class="mb-2 flex flex-wrap items-center gap-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm" data-learning-window="{{ $window->site_id }}">
                <span class="font-medium">{{ $window->site?->name }}</span>
                <span>{{ __('portal.learning.remaining', ['minutes' => intdiv($window->remainingSeconds(), 60), 'seconds' => sprintf('%02d', $window->remainingSeconds() % 60), 'until' => \App\Support\LocalTime::format($window->expires_at, 'H:i')]) }}</span>
                <span class="text-xs text-slate-500">{{ $window->opener?->name ?? '–' }}</span>
                <form method="POST" action="{{ route('admin.learning.close', $window) }}">@csrf
                    <button class="btn-light text-xs">{{ __('portal.learning.close') }}</button></form>
            </div>
        @endforeach
        @if ($learningSites->isNotEmpty())
            <form method="POST" action="{{ route('admin.learning.open') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <label class="label">{{ __('portal.guests.site') }}
                    <select name="site_id" class="field" required>
                        @foreach ($learningSites as $id => $name)
                            @unless ($windows->contains('site_id', $id))<option value="{{ $id }}">{{ $name }}</option>@endunless
                        @endforeach
                    </select></label>
                <label class="label">{{ __('portal.learning.duration') }}
                    <select name="minutes" class="field">
                        @foreach ($durations as $minutes)<option value="{{ $minutes }}" @selected($minutes === 15)>{{ __('portal.learning.minutes', ['minutes' => $minutes]) }}</option>@endforeach
                    </select></label>
                <button class="btn">{{ __('portal.learning.open') }}</button>
            </form>
        @endif
        @if ($attempts->isNotEmpty())
            <div class="mt-4 overflow-x-auto">
                <h3 class="mb-2 font-semibold">{{ __('portal.learning.attempts') }}</h3>
                <table class="data-table">
                    <thead><tr><th>MAC</th><th>{{ __('portal.guests.site') }}</th><th>{{ __('portal.learning.router') }}</th>
                        <th>{{ __('portal.learning.first_seen') }}</th><th>{{ __('portal.learning.last_seen') }}</th><th>{{ __('portal.learning.count') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($attempts as $attempt)
                            <tr data-learning-attempt="{{ $attempt->mac }}">
                                <td class="font-mono">{{ $attempt->mac }}</td>
                                <td class="text-xs">{{ $attempt->site?->name }}@if ($attempt->window?->isOpen()) <span class="gp-badge gp-badge-info">{{ __('portal.learning.window_open') }}</span>@endif</td>
                                <td class="text-xs">{{ $attempt->nasClient?->name ?? '–' }}</td>
                                <td class="text-xs">{{ \App\Support\LocalTime::format($attempt->first_seen_at, 'Y-m-d H:i:s') }}</td>
                                <td class="text-xs">{{ \App\Support\LocalTime::format($attempt->last_seen_at, 'Y-m-d H:i:s') }}</td>
                                <td class="text-xs">{{ $attempt->count }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.learning.take-over', $attempt) }}" class="flex flex-wrap items-center gap-1">@csrf
                                        <input name="comment" required maxlength="255" placeholder="{{ __('portal.whitelist.comment') }}" class="field text-xs" aria-label="{{ __('portal.whitelist.comment') }}">
                                        <button class="btn-light text-xs">{{ __('portal.learning.take_over') }}</button></form>
                                    <form method="POST" action="{{ route('admin.learning.discard', $attempt) }}" class="mt-1">@csrf
                                        <button class="btn-light text-xs">{{ __('portal.learning.discard') }}</button></form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    @endif

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
                            @if ($entry->isValid())
                                <form method="POST" action="{{ route('admin.whitelist.reauth', $entry) }}" class="mt-1">
                                    @csrf <button class="btn-light text-xs" title="{{ __('portal.reauth.button_help') }}">{{ __('portal.reauth.button') }}</button>
                                </form>
                            @endif
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
