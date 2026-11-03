<x-layouts.admin :title="__('portal.guests.title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.guests.title') }}</h1>

    <form method="GET" action="{{ route('admin.guests.index') }}" class="card mb-6 flex flex-wrap items-end gap-4">
        <label class="label">{{ __('portal.guests.search') }}
            <input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" class="field"></label>
        <label class="label">{{ __('portal.guests.site') }}
            <select name="site" class="field">
                <option value="">{{ __('portal.all') }}</option>
                @foreach ($sites as $id => $name)
                    <option value="{{ $id }}" @selected(($filters['site'] ?? null) === $id)>{{ $name }}</option>
                @endforeach
            </select></label>
        <label class="label">{{ __('portal.guests.method') }}
            <select name="method" class="field">
                <option value="">{{ __('portal.all') }}</option>
                @foreach (\App\Enums\GrantType::cases() as $type)
                    <option value="{{ $type->value }}" @selected(($filters['method'] ?? null) === $type->value)>{{ __('portal.guests.methods.'.$type->value) }}</option>
                @endforeach
            </select></label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1" @checked($filters['active'] ?? false)> {{ __('portal.guests.active_only') }}</label>
        <button class="btn">{{ __('portal.audit.filter') }}</button>
    </form>

    @if ($unreachable !== [])
        <p class="mb-4 rounded bg-amber-100 px-4 py-2 text-sm text-amber-800">{{ __('portal.guests.remote_unreachable', ['names' => implode(', ', $unreachable)]) }}</p>
    @endif

    <div class="card overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>{{ __('portal.grants.guest') }}</th><th>{{ __('portal.guests.method') }} / {{ __('portal.guests.site') }}</th>
                <th>{{ __('portal.guests.terms') }}</th><th>{{ __('portal.grants.code') }}</th><th>{{ __('portal.grants.devices') }}</th></tr></thead>
            <tbody>
                @forelse ($guests as $guest)
                    @php($acceptance = $guest->latestAcceptance)
                    @php($grant = $acceptance?->grant)
                    @php($method = $guest->loginMethod($grant))
                    <tr>
                        <td>
                            <div class="font-medium">{{ $guest->last_name }}, {{ $guest->first_name }}</div>
                            @if ($guest->phone_e164)<div class="font-mono text-xs">{{ auth()->user()->displayPhone($guest->phone_e164) }}</div>@endif
                            <div class="text-xs text-slate-500">{{ \App\Support\LocalTime::format($guest->created_at, 'Y-m-d H:i') }}</div>
                        </td>
                        <td>
                            {{ $method ? __('portal.guests.methods.'.$method->value) : '–' }}<br>
                            <span class="text-xs">{{ $sites[$acceptance?->site_id] ?? '–' }}</span>
                        </td>
                        <td class="text-xs">
                            @if ($acceptance)
                                {{ $acceptance->termsVersion ? strtoupper($acceptance->termsVersion->locale).' v'.$acceptance->termsVersion->number : __('portal.guests.generic_terms') }}<br>
                                {{ \App\Support\LocalTime::format($acceptance->accepted_at, 'Y-m-d H:i') }}
                            @else – @endif
                        </td>
                        <td>
                            @if ($grant?->trashed())
                                <span class="font-mono">{{ $grant->code ?? __('portal.guests.methods.'.$grant->type->value) }}</span>
                                <div class="text-xs text-slate-500">{{ __('portal.guests.grant_deleted') }}</div>
                            @elseif ($grant)
                                <a href="{{ route('admin.grants.edit', $grant) }}" class="font-mono underline">{{ $grant->code ?? __('portal.guests.methods.'.$grant->type->value) }}</a>
                                <div class="text-xs">{{ $grant->status()->label() }}@if ($grant->isUsable() && $grant->remainingSeconds() !== null) · {{ __('portal.grants.remaining') }} {{ \App\Support\Duration::format($grant->remainingSeconds()) }}@endif</div>
                                @unless ($grant->revoked_at)
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <form method="POST" action="{{ route('admin.grants.extend', $grant) }}" class="flex gap-1">
                                            @csrf
                                            <input type="number" step="1" min="1" name="hours" value="24" aria-label="{{ __('portal.grants.extend_hours') }}" class="field w-20 px-2 py-1 text-xs">
                                            <button class="btn-light text-xs">{{ __('portal.guests.extend') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.grants.revoke', $grant) }}" onsubmit="return confirm(@js(__('portal.confirm')))">
                                            @csrf <button class="btn-danger text-xs">{{ __('portal.grants.revoke') }}</button>
                                        </form>
                                    </div>
                                @endunless
                            @else
                                <span class="text-xs text-slate-500">{{ __('portal.guests.no_grant') }}</span>
                            @endif
                        </td>
                        <td class="text-xs">
                            @forelse ($guest->devices->sortByDesc('bound_at') as $device)
                                @php($session = ($sessions[$device->grant_id.'|'.$device->mac] ?? collect())->first())
                                @php($open = $session && $session->stopped_at === null)
                                {{-- Open but without accounting update for too long: Stop probably lost. --}}
                                @php($stale = $open && ($session->accounting_max_last_update_at ? \Illuminate\Support\Carbon::parse($session->accounting_max_last_update_at) : $session->started_at)->lt($staleBefore))
                                @php($online = $open && ! $stale)
                                <div class="mb-2">
                                    <span class="font-mono">{{ $device->mac }}</span>
                                    @if ($stale)
                                        <span class="rounded bg-amber-100 px-1 text-amber-800" title="{{ __('portal.guests.stale_help') }}">{{ __('portal.guests.stale') }}</span>
                                    @else
                                        <span @class(['font-semibold text-green-700' => $online, 'text-slate-500' => ! $online])>{{ $online ? __('portal.grants.online') : __('portal.guests.offline') }}</span>
                                    @endif
                                    @if ($device->unbound_at) <span class="text-slate-500">({{ __('portal.grants.unbound_at') }} {{ \App\Support\LocalTime::format($device->unbound_at, 'Y-m-d H:i') }})</span>@endif
                                    @if ($entry = $whitelisted[$device->mac] ?? null)
                                        <a href="{{ route('admin.whitelist.edit', $entry) }}" class="rounded bg-sky-100 px-1 text-sky-800 underline">{{ __('portal.whitelist.badge') }}@unless ($entry->isValid()) ({{ __('portal.whitelist.not_valid') }})@endunless</a>
                                    @else
                                        <a href="{{ route('admin.whitelist.create', ['device' => $device->id]) }}" class="underline">{{ __('portal.whitelist.allow_device') }}</a>
                                    @endif
                                    @if ($session)
                                        <div>IP {{ $session->ip ?? '–' }} · {{ __('portal.grants.started_at') }} {{ \App\Support\LocalTime::format($session->started_at, 'Y-m-d H:i') }}@if ($session->stopped_at) · {{ __('portal.grants.stopped_at') }} {{ \App\Support\LocalTime::format($session->stopped_at, 'Y-m-d H:i') }}@endif</div>
                                        @if ($session->accounting_sum_bytes_in !== null)
                                            <div>↓ {{ \App\Support\Bytes::format($session->accounting_sum_bytes_out) }} · ↑ {{ \App\Support\Bytes::format($session->accounting_sum_bytes_in) }}</div>
                                        @endif
                                        @if ($session->serving_instance_id !== $localInstance && $session->serving_instance_id)
                                            <div class="text-slate-500">{{ __('portal.guests.served_by', ['name' => $instanceNames[$session->serving_instance_id] ?? $session->serving_instance_id]) }}</div>
                                        @endif
                                        @if ($open && $session->serving_instance_id === $localInstance && ! $session->remote)
                                            <form method="POST" action="{{ route('admin.sessions.disconnect', $session) }}" class="mt-1" onsubmit="return confirm(@js(__('portal.confirm')))">
                                                @csrf <button class="btn-light text-xs">{{ __('portal.guests.disconnect') }}</button>
                                            </form>
                                        @elseif ($open && $session->remote)
                                            <form method="POST" action="{{ route('admin.sessions.disconnect-remote', [$session->serving_instance_id, $session->id]) }}" class="mt-1" onsubmit="return confirm(@js(__('portal.confirm')))">
                                                @csrf <button class="btn-light text-xs">{{ __('portal.guests.disconnect') }}</button>
                                            </form>
                                        @endif
                                    @endif
                                </div>
                            @empty
                                –
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($guests->hasPages())
            <nav class="mt-4 flex items-center justify-between text-sm">
                @if ($guests->onFirstPage()) <span></span> @else <a href="{{ $guests->previousPageUrl() }}" class="btn-light">‹ {{ __('portal.audit.newer') }}</a> @endif
                <span>{{ __('portal.audit.page', ['page' => $guests->currentPage(), 'pages' => $guests->lastPage()]) }}</span>
                @if ($guests->hasMorePages()) <a href="{{ $guests->nextPageUrl() }}" class="btn-light">{{ __('portal.audit.older') }} ›</a> @else <span></span> @endif
            </nav>
        @endif
    </div>
</x-layouts.admin>
