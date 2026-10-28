<x-layouts.admin :title="__('portal.approvals.title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.approvals.title') }}</h1>

    <section class="card mb-6">
        <h2 class="mb-4 text-lg font-semibold">{{ __('portal.approvals.pending') }}</h2>
        <table class="data-table">
            <thead><tr><th>{{ __('portal.grants.guest') }}</th><th>{{ __('portal.approvals.site') }}</th><th>{{ __('portal.grants.device') }}</th><th>{{ __('portal.approvals.requested_at') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($pending as $request)
                    <tr>
                        <td>{{ $request->guestName() }}</td>
                        <td>{{ $request->site?->name }}</td>
                        <td class="font-mono">{{ $request->mac }}</td>
                        <td>{{ $request->created_at->format('d.m.Y H:i') }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.approvals.decide', $request) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                @foreach (array_keys(config('portal.duration_presets')) as $key)
                                    <button name="decision" value="{{ $key }}" class="btn-light">{{ __('portal.approvals.choices.'.$key) }}</button>
                                @endforeach
                                <input name="custom_hours" type="number" step="0.25" min="0.25" max="8760" class="field w-20 py-1 text-sm"
                                       placeholder="h" aria-label="{{ __('portal.grants.custom_hours') }}">
                                <button name="decision" value="custom" class="btn-light">{{ __('portal.grants.presets.custom') }}</button>
                                <button name="decision" value="deny" class="btn-danger">{{ __('portal.approvals.choices.deny') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2 class="mb-4 text-lg font-semibold">{{ __('portal.approvals.decided') }}</h2>
        <table class="data-table">
            <thead><tr><th>{{ __('portal.grants.guest') }}</th><th>{{ __('portal.approvals.site') }}</th><th>{{ __('portal.grants.device') }}</th><th>{{ __('portal.approvals.requested_at') }}</th><th>{{ __('portal.grants.status') }}</th><th>{{ __('portal.approvals.decided_by') }}</th><th>{{ __('portal.grants.duration') }}</th></tr></thead>
            <tbody>
                @forelse ($decided as $request)
                    <tr>
                        <td>{{ $request->guestName() }}</td>
                        <td>{{ $request->site?->name }}</td>
                        <td class="font-mono">{{ $request->mac }}</td>
                        <td>{{ $request->created_at->format('d.m.Y H:i') }}</td>
                        <td>{{ $request->currentStatus()->label() }}</td>
                        <td>{{ $request->decided_by ?? '–' }}</td>
                        <td>
                            @if ($request->grant_id)
                                <a href="{{ route('admin.grants.edit', $request->grant_id) }}" class="underline">{{ \App\Support\Duration::format($request->duration_s) }}</a>
                            @else – @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.admin>
