<x-layouts.admin :title="__('portal.federation.title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.federation.title') }}</h1>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.federation.help') }}</p>

    @if ($limitedMode)
        <p class="mb-4 rounded bg-amber-100 px-4 py-2 text-sm text-amber-800">{{ __('portal.federation.limited_mode_active') }}</p>
    @endif

    @if ($join = session('federation_join'))
        <section class="card mb-6 border-2 border-sky-300">
            <h2 class="mb-2 text-lg font-semibold">{{ __('portal.federation.token_title') }}</h2>
            <p class="mb-2 text-sm">{{ __('portal.federation.token_help', ['time' => \App\Support\LocalTime::format(\Illuminate\Support\Carbon::parse($join['expires_at']), 'H:i')]) }}</p>
            @if (! $join['url'] || ! $join['pin'])
                <p class="mb-2 text-sm text-red-700">{{ __('portal.federation.token_incomplete') }}</p>
            @endif
            <x-admin.code :title="__('portal.federation.join_command')"
                :code="'artisan portal:federation:join '.($join['url'] ?? 'https://…').' '.$join['token'].' '.($join['pin'] ?? 'sha256//…').' --name=\'…\' --federation-url=https://…'" />
            <p class="text-xs text-slate-500">{{ __('portal.federation.join_command_help') }}</p>
        </section>
    @endif

    @if ($checklist = session('federation_checklist'))
        <section class="card mb-6 border-2 border-amber-300">
            <h2 class="mb-2 text-lg font-semibold">{{ __('portal.federation.checklist_title') }}</h2>
            <p class="mb-2 text-sm">{{ __('portal.federation.checklist_help') }}</p>
            <ul class="list-disc pl-6 text-sm">
                <li>{{ __('portal.federation.checklist_key') }}</li>
                @foreach ($checklist as $item)<li>{{ $item }}</li>@endforeach
            </ul>
        </section>
    @endif

    <form method="POST" action="{{ route('admin.federation.instance') }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf @method('PUT')
        <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.federation.this_instance') }}
            @if ($isHub)<span class="ml-2 rounded bg-sky-100 px-2 text-sm text-sky-800">{{ __('portal.federation.hub') }}</span>@endif</h2>
        <dl class="grid gap-1 text-sm md:col-span-2 md:grid-cols-[12rem_1fr]">
            <dt class="text-slate-500">{{ __('portal.federation.instance_id') }}</dt><dd class="font-mono">{{ $self->id }}</dd>
            <dt class="text-slate-500">{{ __('portal.federation.fingerprint') }}</dt>
            <dd class="font-mono break-all">{{ $localPin ?? __('portal.federation.no_certificate') }}</dd>
            <dt class="text-slate-500">{{ __('portal.federation.signing_key') }}</dt><dd class="font-mono break-all">{{ $self->signing_public_key ?? '–' }}</dd>
            @if ($joinedAt)<dt class="text-slate-500">{{ __('portal.federation.joined_at') }}</dt><dd>{{ \App\Support\LocalTime::format(\Illuminate\Support\Carbon::parse($joinedAt), 'Y-m-d H:i') }}</dd>@endif
        </dl>
        <label class="label">{{ __('portal.federation.name') }}
            <input name="name" value="{{ old('name', $self->name) }}" required maxlength="100" class="field"></label>
        <label class="label">{{ __('portal.federation.federation_url') }}
            <input name="federation_url" value="{{ old('federation_url', $self->federation_url) }}" placeholder="https://10.0.0.2:9443" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.federation.federation_url_help') }}</span></label>
        <label class="label">{{ __('portal.federation.admin_url') }}
            <input name="admin_url" value="{{ old('admin_url', $self->admin_url) }}" placeholder="https://portal.example.com:8443" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.federation.admin_url_help') }}</span></label>
        <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
    </form>

    <form method="POST" action="{{ route('admin.federation.settings') }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf @method('PUT')
        <h2 class="text-lg font-semibold md:col-span-2">{{ __('portal.federation.settings') }}</h2>
        <label class="label">{{ __('portal.federation.hub_instance') }}
            @unless ($isHub)<input type="hidden" name="hub" value="{{ $hubId }}">@endunless
            <select name="hub" class="field" @disabled(! $isHub)>
                @foreach ($instances as $instance)
                    <option value="{{ $instance->id }}" @selected(old('hub', $hubId ?? $self->id) === $instance->id)>{{ $instance->name }}@if ($instance->id === $self->id) ({{ __('portal.federation.this') }})@endif</option>
                @endforeach
            </select>
            <span class="text-xs text-slate-500">{{ __('portal.federation.hub_help') }}</span></label>
        <label class="flex items-start gap-2 text-sm">
            <input type="hidden" name="offline_sms_only" value="0">
            <input type="checkbox" name="offline_sms_only" value="1" @checked(old('offline_sms_only', $offlineSmsOnly)) class="mt-0.5">
            <span>{{ __('portal.federation.offline_sms_only') }}<br><span class="text-xs text-slate-500">{{ __('portal.federation.offline_sms_only_help') }}</span></span>
        </label>
        <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
    </form>

    <section class="card mb-6">
        <div class="mb-4 flex items-center justify-between gap-4">
            <h2 class="text-lg font-semibold">{{ __('portal.federation.peers') }}</h2>
            <form method="POST" action="{{ route('admin.federation.token') }}">
                @csrf <button class="btn">{{ __('portal.federation.create_token') }}</button></form>
        </div>
        <table class="data-table">
            <thead><tr><th>{{ __('portal.federation.name') }}</th><th>{{ __('portal.federation.last_seen') }}</th><th>{{ __('portal.federation.lag') }}</th><th>{{ __('portal.federation.skew') }}</th><th>{{ __('portal.federation.state') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($peers as $peer)
                    <tr>
                        <td>
                            {{ $peer->instance->name }}
                            @if ($peer->isHub)<span class="rounded bg-sky-100 px-1 text-xs text-sky-800">{{ __('portal.federation.hub') }}</span>@endif
                            <div class="font-mono text-xs text-slate-500">{{ $peer->instance->federation_url ?? '–' }}</div>
                            @unless ($peer->direct)<div class="text-xs text-slate-500">{{ __('portal.federation.via_hub') }}</div>@endunless
                        </td>
                        <td class="text-sm">
                            @if ($peer->state?->last_seen_at){{ \App\Support\LocalTime::format($peer->state->last_seen_at, 'Y-m-d H:i:s') }}@else – @endif
                            @if ($peer->state?->last_error)<div class="text-xs text-red-700">{{ $peer->state->last_error }}</div>@endif
                        </td>
                        <td class="text-sm">
                            @if ($peer->incoming !== null || $peer->outgoing !== null)
                                {{ __('portal.federation.lag_value', ['in' => $peer->incoming ?? '–', 'out' => $peer->outgoing ?? '–']) }}
                            @else – @endif
                            <div class="text-xs text-slate-500">{{ __('portal.federation.applied', ['seq' => $peer->applied]) }}</div>
                        </td>
                        <td @class(['text-sm', 'font-semibold text-amber-700' => $peer->skewWarning])>
                            @if ($peer->state?->clock_skew_ms !== null){{ number_format($peer->state->clock_skew_ms / 1000, 1) }} s @else – @endif
                            @if ($peer->skewWarning)<div class="text-xs">{{ __('portal.federation.skew_warning') }}</div>@endif
                        </td>
                        <td class="text-sm">
                            @if ($peer->state?->paused_at || $peer->applyPause)
                                <span class="font-semibold text-red-700">{{ __('portal.federation.paused') }}</span>
                                <div class="text-xs text-red-700">{{ $peer->state?->pause_reason }} {{ $peer->applyPause }}</div>
                            @else
                                <span class="text-green-700">{{ __('portal.federation.running') }}</span>
                            @endif
                            @if ($peer->state?->protocol !== null && $peer->state->protocol !== \App\Federation\Federation::FEDERATION_PROTOCOL)
                                <div class="text-xs text-amber-700">{{ __('portal.federation.protocol_warning', ['theirs' => $peer->state->protocol, 'ours' => \App\Federation\Federation::FEDERATION_PROTOCOL]) }}</div>
                            @endif
                        </td>
                        <td class="flex flex-wrap gap-2">
                            @if ($peer->state?->paused_at || $peer->applyPause)
                                <form method="POST" action="{{ route('admin.federation.unpause', $peer->instance) }}">
                                    @csrf <button class="btn-light">{{ __('portal.federation.unpause') }}</button></form>
                            @endif
                            @unless ($peer->isHub)
                                <form method="POST" action="{{ route('admin.federation.destroy', $peer->instance) }}" onsubmit="return confirm(@js(__('portal.federation.remove_confirm')))">
                                    @csrf @method('DELETE') <button class="btn-danger">{{ __('portal.federation.remove') }}</button></form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-slate-500">{{ __('portal.federation.no_peers') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.admin>
