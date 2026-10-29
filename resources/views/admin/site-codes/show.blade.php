<x-layouts.admin :title="__('portal.site_codes.title').' · '.$site->name">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">{{ __('portal.site_codes.title') }} · {{ $site->name }}</h1>
        <a href="{{ route('admin.site-codes.index') }}" class="btn-light">{{ __('portal.back') }}</a>
    </div>

    <section class="card mb-6">
        <h2 class="mb-3 text-lg font-semibold">{{ __('portal.site_codes.current') }}</h2>
        @if ($current)
            <div class="mb-4 flex flex-wrap items-center gap-6">
                <div class="font-mono text-4xl tracking-widest">{{ $current->code }}</div>
                <div class="grid gap-1 text-sm">
                    <div>{{ __('portal.site_codes.duration') }}: {{ \App\Support\Duration::format($current->duration_s) }}</div>
                    <div>{{ __('portal.site_codes.valid_until') }}: {{ \App\Support\LocalTime::format($current->valid_until, 'Y-m-d H:i') ?? __('portal.site_codes.unlimited') }}
                        @if ($current->isExpired()) <span class="rounded bg-amber-100 px-1 text-amber-800">{{ __('portal.grants.statuses.expired') }}</span>@endif</div>
                    <div>{{ __('portal.site_codes.devices_running') }}: {{ $current->running_grants_count }} / {{ $current->max_devices ?? __('portal.site_codes.unlimited') }}</div>
                </div>
            </div>
            <div class="mb-4 flex flex-wrap gap-2">
                <a href="{{ route('admin.site-codes.print', [$site, 'format' => 'a4']) }}" class="btn-light" target="_blank">{{ __('portal.site_codes.print_a4') }}</a>
                <a href="{{ route('admin.site-codes.print', [$site, 'format' => 'a6']) }}" class="btn-light" target="_blank">{{ __('portal.site_codes.print_a6') }}</a>
                <form method="POST" action="{{ route('admin.site-codes.end-devices', $site) }}" onsubmit="return confirm(@js(__('portal.site_codes.end_devices_confirm')))">
                    @csrf <input type="hidden" name="site_code" value="{{ $current->id }}">
                    <button class="btn-light">{{ __('portal.site_codes.end_devices') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.site-codes.deactivate', $site) }}" onsubmit="return confirm(@js(__('portal.site_codes.deactivate_confirm')))">
                    @csrf <button class="btn-danger">{{ __('portal.site_codes.deactivate') }}</button>
                </form>
            </div>
            <form method="POST" action="{{ route('admin.site-codes.update', $site) }}" class="grid gap-4 border-t border-(--gp-divider) pt-4 md:grid-cols-2">
                @csrf @method('PUT')
                @include('admin.site-codes._fields', ['code' => $current])
                <div class="md:col-span-2 flex flex-wrap items-center gap-3">
                    <button class="btn">{{ __('portal.save') }}</button>
                    <span class="text-xs text-slate-500">{{ __('portal.site_codes.update_help') }}</span>
                </div>
            </form>
        @else
            <p class="text-sm text-slate-500">{{ __('portal.site_codes.none_active') }}</p>
        @endif
    </section>

    <section class="card mb-6">
        <h2 class="mb-1 text-lg font-semibold">{{ __('portal.site_codes.generate') }}</h2>
        <p class="mb-3 text-sm text-slate-500">{{ __('portal.site_codes.generate_help') }}</p>
        <form method="POST" action="{{ route('admin.site-codes.store', $site) }}" class="grid gap-4 md:grid-cols-2"
              onsubmit="return confirm(@js(__('portal.site_codes.generate_confirm')))">
            @csrf
            @include('admin.site-codes._fields', ['code' => $current])
            <div class="md:col-span-2"><button class="btn">{{ __('portal.site_codes.generate') }}</button></div>
        </form>
    </section>

    <section class="card overflow-x-auto">
        <h2 class="mb-3 text-lg font-semibold">{{ __('portal.site_codes.history') }}</h2>
        <table class="data-table">
            <thead><tr><th>{{ __('portal.site_codes.code') }}</th><th>{{ __('portal.site_codes.created') }}</th><th>{{ __('portal.site_codes.deactivated_col') }}</th>
                <th>{{ __('portal.site_codes.duration') }}</th><th>{{ __('portal.site_codes.logins') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($history as $code)
                    <tr>
                        <td class="font-mono">{{ $code->code }}@if ($code->isActive()) <span class="rounded bg-green-100 px-1 font-sans text-xs text-green-800">{{ __('portal.site_codes.active') }}</span>@endif</td>
                        <td>{{ \App\Support\LocalTime::format($code->created_at, 'Y-m-d H:i') }}<br><span class="text-xs text-slate-500">{{ $code->creator?->name ?? '–' }}</span></td>
                        <td>@if ($code->deactivated_at){{ \App\Support\LocalTime::format($code->deactivated_at, 'Y-m-d H:i') }}<br><span class="text-xs text-slate-500">{{ $code->deactivator?->name ?? '–' }}</span>@else – @endif</td>
                        <td>{{ \App\Support\Duration::format($code->duration_s) }}</td>
                        <td>{{ $code->grants_count }} <span class="text-xs text-slate-500">({{ __('portal.site_codes.running', ['count' => $code->running_grants_count]) }})</span></td>
                        <td>
                            @if (! $code->isActive() && $code->running_grants_count > 0)
                                <form method="POST" action="{{ route('admin.site-codes.end-devices', $site) }}" onsubmit="return confirm(@js(__('portal.site_codes.end_devices_confirm')))">
                                    @csrf <input type="hidden" name="site_code" value="{{ $code->id }}">
                                    <button class="btn-light text-xs">{{ __('portal.site_codes.end_devices') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.admin>
