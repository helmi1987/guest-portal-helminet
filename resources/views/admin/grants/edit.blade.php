<x-layouts.admin :title="__('portal.grants.code').' '.$grant->code">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('portal.grants.code') }} <span class="font-mono">{{ $grant->code }}</span> · {{ $grant->status()->label() }}</h1>
        @if ($grant->type === \App\Enums\GrantType::Code)
            <a href="{{ route('admin.grants.print', ['ids' => [$grant->id]]) }}" class="btn-light">{{ __('portal.grants.print') }}</a>
        @elseif ($grant->site_code_id)
            <a href="{{ route('admin.site-codes.show', $grant->siteCode->site_id) }}" class="btn-light">{{ __('portal.site_codes.title') }}</a>
        @endif
    </div>

    <section class="card mb-6 grid gap-2 text-sm md:grid-cols-3">
        <div>{{ __('portal.grants.duration') }}: {{ \App\Support\Duration::format($grant->duration_s) }} ({{ $grant->expiry_mode->label() }})</div>
        <div>{{ __('portal.grants.first_login') }}: {{ \App\Support\LocalTime::format($grant->first_login_at, 'Y-m-d H:i') ?? '–' }}</div>
        <div>{{ __('portal.grants.expires_at') }}: {{ \App\Support\LocalTime::format($grant->expires_at, 'Y-m-d H:i') ?? '–' }}
            @if ($grant->remainingSeconds()) ({{ __('portal.grants.remaining') }} {{ \App\Support\Duration::format($grant->remainingSeconds()) }}) @endif</div>
        <div>{{ __('portal.grants.extensions') }}: {{ $grant->extensions_used }}{{ $grant->max_extensions !== null ? ' / '.$grant->max_extensions : '' }}</div>
        <div>{{ __('portal.grants.revoked_at') }}: {{ \App\Support\LocalTime::format($grant->revoked_at, 'Y-m-d H:i') ?? '–' }}</div>
        @if ($grant->type === \App\Enums\GrantType::Code && $grant->code && ($ssid = $qr->ssidFor($grant)))
            <div class="flex items-center gap-3 md:col-span-3">
                <div class="h-28 w-28 shrink-0 bg-white">{!! $qr->wifiSvg($ssid, 112, $qr->passwordFor($grant)) !!}</div>
                <div>{{ __('portal.grants.wifi_qr', ['ssid' => $ssid]) }}</div>
            </div>
        @endif
    </section>

    @unless ($grant->revoked_at)
        <section class="card mb-6 flex flex-wrap items-end gap-6">
            <form method="POST" action="{{ route('admin.grants.extend', $grant) }}" class="flex items-end gap-2">
                @csrf
                <label class="label">{{ __('portal.grants.extend_hours') }}
                    <input type="number" step="1" name="hours" value="24" class="field w-28"></label>
                <button class="btn-light">{{ __('portal.grants.apply') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.grants.expiry', $grant) }}" class="flex items-end gap-2">
                @csrf
                <label class="label">{{ __('portal.grants.set_expiry') }}
                    <input type="datetime-local" name="expires_at" value="{{ \App\Support\LocalTime::format($grant->expires_at, 'Y-m-d\TH:i') }}" required class="field"></label>
                <button class="btn-light">{{ __('portal.grants.apply') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.grants.revoke', $grant) }}" onsubmit="return confirm(@js(__('portal.confirm')))">
                @csrf <button class="btn-danger">{{ __('portal.grants.revoke') }}</button>
            </form>
        </section>
    @endunless

    <form method="POST" action="{{ route('admin.grants.update', $grant) }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf @method('PUT')
        @include('admin.grants._fields', ['grant' => $grant])
        <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
    </form>

    <section class="card">
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.grants.devices') }}</h2>
        <table class="data-table mb-6">
            <thead><tr><th>MAC</th><th>{{ __('portal.grants.guest') }}</th><th>{{ __('portal.grants.bound_at') }}</th><th>{{ __('portal.grants.unbound_at') }}</th></tr></thead>
            <tbody>
                @forelse ($grant->devices->sortByDesc('bound_at') as $device)
                    <tr><td class="font-mono">{{ $device->mac }}</td><td>{{ $device->guest ? $device->guest->first_name.' '.$device->guest->last_name : '–' }}@if ($device->guest?->phone_e164)<br><span class="font-mono text-xs">{{ auth()->user()->displayPhone($device->guest->phone_e164) }}</span>@endif</td>
                        <td>{{ \App\Support\LocalTime::format($device->bound_at, 'Y-m-d H:i') }}</td><td>{{ \App\Support\LocalTime::format($device->unbound_at, 'Y-m-d H:i') ?? '–' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.grants.sessions') }}</h2>
        <table class="data-table">
            <thead><tr><th>MAC</th><th>IP</th><th>{{ __('portal.grants.started_at') }}</th><th>{{ __('portal.grants.stopped_at') }}</th></tr></thead>
            <tbody>
                @forelse ($grant->sessions as $session)
                    <tr><td class="font-mono">{{ $session->mac }}</td><td>{{ $session->ip ?? '–' }}</td>
                        <td>{{ \App\Support\LocalTime::format($session->started_at, 'Y-m-d H:i') }}</td><td>{{ \App\Support\LocalTime::format($session->stopped_at, 'Y-m-d H:i') ?? __('portal.grants.online') }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.admin>
