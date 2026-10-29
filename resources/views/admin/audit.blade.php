<x-layouts.admin :title="__('portal.audit.title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.audit.title') }}</h1>

    <form method="GET" action="{{ route('admin.audit') }}" class="card mb-6 flex flex-wrap items-end gap-4">
        <label class="label">{{ __('portal.audit.group') }}
            <select name="group" class="field">
                <option value="">{{ __('portal.all') }}</option>
                @foreach (\App\Support\Audit::GROUPS as $group)
                    <option value="{{ $group }}" @selected(($filters['group'] ?? null) === $group)>{{ __('portal.audit.groups.'.$group) }}</option>
                @endforeach
            </select></label>
        <label class="label">{{ __('portal.audit.user') }}
            <select name="user" class="field">
                <option value="">{{ __('portal.all') }}</option>
                @foreach ($users as $id => $name)
                    <option value="{{ $id }}" @selected(($filters['user'] ?? null) === $id)>{{ $name }}</option>
                @endforeach
            </select></label>
        <label class="label">{{ __('portal.audit.from') }}
            <input name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="field"></label>
        <label class="label">{{ __('portal.audit.to') }}
            <input name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="field"></label>
        <label class="label">{{ __('portal.audit.subject') }}
            <input name="subject" value="{{ $filters['subject'] ?? '' }}" maxlength="64" class="field font-mono"></label>
        <button class="btn">{{ __('portal.audit.filter') }}</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>{{ __('portal.audit.time') }}</th><th>{{ __('portal.audit.actor') }}</th><th>{{ __('portal.audit.action') }}</th><th>{{ __('portal.audit.subject') }}</th><th>{{ __('portal.audit.data') }}</th><th>IP</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="whitespace-nowrap">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
                        <td>{{ $log->actor ?? $log->user?->name ?? '–' }}</td>
                        <td class="font-mono">{{ $log->action }}</td>
                        <td class="font-mono text-xs">
                            @if ($log->subject_type)
                                {{ $log->subject_type }}<br>
                                <a href="{{ route('admin.audit', ['subject' => $log->subject_id]) }}" class="underline">{{ $log->subject_id }}</a>
                            @else – @endif
                        </td>
                        <td class="font-mono text-xs break-all">{{ $log->data ? json_encode($log->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</td>
                        <td class="font-mono text-xs">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($logs->hasPages())
            <nav class="mt-4 flex items-center justify-between text-sm">
                @if ($logs->onFirstPage()) <span></span> @else <a href="{{ $logs->previousPageUrl() }}" class="btn-light">‹ {{ __('portal.audit.newer') }}</a> @endif
                <span>{{ __('portal.audit.page', ['page' => $logs->currentPage(), 'pages' => $logs->lastPage()]) }}</span>
                @if ($logs->hasMorePages()) <a href="{{ $logs->nextPageUrl() }}" class="btn-light">{{ __('portal.audit.older') }} ›</a> @else <span></span> @endif
            </nav>
        @endif
    </div>
</x-layouts.admin>
