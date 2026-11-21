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
        @include('admin.whitelist.partials.learning-panel')
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
    </section>
    @endif
    @if (auth()->user()->isAdmin())
    <script>
    (function () {
        // Learning window panel: refresh every 5 s while a window is open, countdown every second (v0.33.2)
        var d = document, poll = null, tick = null, busy = false, lastExpiry = 0;
        function panel() { return d.querySelector('[data-learning-panel]'); }
        function url() { var p = panel(); return p ? p.getAttribute('data-learning-poll') : null; }
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function countdown() {
            var p = panel(), expired = false;
            if (!p) return;
            p.querySelectorAll('[data-learning-remaining]').forEach(function (el) {
                if (el._deadline === undefined) el._deadline = Date.now() + parseInt(el.getAttribute('data-learning-remaining'), 10) * 1000;
                var left = Math.max(0, Math.ceil((el._deadline - Date.now()) / 1000));
                var m = el.querySelector('[data-cd-m]'), s = el.querySelector('[data-cd-s]');
                if (m) m.textContent = Math.floor(left / 60);
                if (s) s.textContent = pad(left % 60);
                if (left === 0) expired = true;
            });
            // Window ran out: one last refresh (throttled), polling stops when the server reports no open window
            if (expired && Date.now() - lastExpiry > 2000) { lastExpiry = Date.now(); refresh(); }
        }
        function busyInput(p) {
            // Do not clobber a comment being typed
            var a = d.activeElement;
            if (a && p.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return true;
            return Array.prototype.some.call(p.querySelectorAll('input[name="comment"]'), function (i) { return i.value !== ''; });
        }
        function stop() { clearInterval(poll); clearInterval(tick); poll = tick = null; }
        function start() {
            if (!url() || d.visibilityState === 'hidden') return;
            if (!poll) poll = setInterval(refresh, 5000);
            if (!tick) tick = setInterval(countdown, 1000);
        }
        function refresh() {
            var p = panel(), u = url();
            if (!p || !u) { stop(); return; }
            if (busy) return;
            busy = true;
            fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
                .then(function (html) {
                    var t = d.createElement('template'); t.innerHTML = html.trim();
                    var fresh = t.content.firstElementChild, cur = panel();
                    if (!fresh || !cur) return;
                    if (!busyInput(cur)) cur.replaceWith(fresh);
                    if (!url()) stop();
                })
                .catch(function () { stop(); })
                .then(function () { busy = false; });
        }
        d.addEventListener('visibilitychange', function () {
            if (d.visibilityState === 'hidden') { stop(); return; }
            if (url()) { refresh(); start(); }
        });
        start();
    })();
    </script>
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
