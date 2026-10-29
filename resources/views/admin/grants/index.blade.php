<x-layouts.admin :title="__('portal.grants.title')">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('portal.grants.title') }}</h1>
        <a href="{{ route('admin.grants.create') }}" class="btn">{{ __('portal.grants.create') }}</a>
    </div>
    <nav class="mb-4 flex flex-wrap gap-2 text-sm">
        <a href="{{ route('admin.grants.index') }}" @class(['btn-light', 'font-semibold' => ! $status])>{{ __('portal.all') }}</a>
        @foreach (\App\Enums\GrantStatus::cases() as $case)
            <a href="{{ route('admin.grants.index', ['status' => $case->value]) }}" @class(['btn-light', 'font-semibold' => $status === $case])>{{ $case->label() }}</a>
        @endforeach
    </nav>
    <form method="GET" action="{{ route('admin.grants.print') }}" class="card">
        <table class="data-table">
            <thead><tr><th></th><th>{{ __('portal.grants.code') }}</th><th>{{ __('portal.grants.label') }}</th><th>{{ __('portal.grants.status') }}</th>
                <th>{{ __('portal.grants.duration') }}</th><th>{{ __('portal.grants.expires_at') }}</th><th>{{ __('portal.grants.device') }}</th><th>{{ __('portal.grants.sites') }}</th></tr></thead>
            <tbody>
                @forelse ($grants as $grant)
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $grant->id }}"></td>
                        <td class="font-mono"><a href="{{ route('admin.grants.edit', $grant) }}" class="underline">{{ $grant->code ?? '–' }}</a></td>
                        <td>{{ $grant->label }}</td>
                        <td>{{ $grant->status()->label() }}</td>
                        <td>{{ \App\Support\Duration::format($grant->duration_s) }}</td>
                        <td>{{ \App\Support\LocalTime::format($grant->expires_at, 'Y-m-d H:i') ?? '–' }}</td>
                        <td class="font-mono text-xs">{{ $grant->devices->first()?->mac ?? '–' }}</td>
                        <td>{{ $grant->siteLinks->isEmpty() ? __('portal.all') : $grant->siteLinks->map(fn ($l) => $sites[$l->site_id] ?? '?')->join(', ') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <button class="btn-light mt-4">{{ __('portal.grants.print_selected') }}</button>
    </form>
</x-layouts.admin>
