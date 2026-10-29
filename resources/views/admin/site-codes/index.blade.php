<x-layouts.admin :title="__('portal.site_codes.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.site_codes.title') }}</h1>
    <p class="mb-4 text-sm text-slate-500">{{ __('portal.site_codes.help') }}</p>
    <div class="card overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>{{ __('portal.guests.site') }}</th><th>{{ __('portal.site_codes.code') }}</th><th>{{ __('portal.site_codes.duration') }}</th>
                <th>{{ __('portal.site_codes.valid_until') }}</th><th>{{ __('portal.site_codes.devices_running') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($sites as $site)
                    @php($code = $current[$site->id] ?? null)
                    <tr>
                        <td>{{ $site->name }}</td>
                        @if ($code)
                            <td class="font-mono">{{ $code->code }}@if ($code->isExpired()) <span class="text-xs text-slate-500">({{ __('portal.grants.statuses.expired') }})</span>@endif</td>
                            <td>{{ \App\Support\Duration::format($code->duration_s) }}</td>
                            <td>{{ \App\Support\LocalTime::format($code->valid_until, 'Y-m-d H:i') ?? '–' }}</td>
                            <td>{{ $code->running_grants_count }}{{ $code->max_devices !== null ? ' / '.$code->max_devices : '' }}</td>
                        @else
                            <td colspan="4" class="text-slate-500">{{ __('portal.site_codes.none_active') }}</td>
                        @endif
                        <td><a href="{{ route('admin.site-codes.show', $site) }}" class="btn-light">{{ __('portal.site_codes.manage') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.admin>
