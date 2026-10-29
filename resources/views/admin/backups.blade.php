<x-layouts.admin :title="__('portal.backups.title')">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('portal.backups.title') }}</h1>
        <form method="POST" action="{{ route('admin.backups.store') }}">
            @csrf <button class="btn">{{ __('portal.backups.create') }}</button></form>
    </div>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.backups.help', ['dir' => $dir]) }}</p>
    @if ($insecure)
        <p class="mb-4 rounded bg-amber-100 px-4 py-2 text-sm text-amber-800">{{ __('portal.backups.dir_insecure', ['dir' => $dir] + $insecure) }}</p>
    @endif

    <div class="card mb-6">
        @if ($backups === [])
            <p class="text-sm text-slate-500">{{ __('portal.none') }}</p>
        @else
            <table class="data-table">
                <thead><tr><th>{{ __('portal.backups.file') }}</th><th>{{ __('portal.backups.created_at') }}</th><th>{{ __('portal.backups.size') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($backups as $backup)
                        <tr>
                            <td><a href="{{ route('admin.backups.download', $backup['name']) }}" class="underline">{{ $backup['name'] }}</a></td>
                            <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($backup['created_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                            <td>{{ number_format($backup['size'] / 1024, 0, '.', "'") }} KB</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('admin.backups.destroy', $backup['name']) }}" onsubmit="return confirm(@js(__('portal.confirm')))">
                                    @csrf @method('DELETE') <button class="btn-light">{{ __('portal.delete') }}</button></form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.backups.settings') }}" class="card flex flex-wrap items-end gap-2">
        @csrf
        <label class="label">{{ __('portal.backups.keep') }}
            <input name="keep" type="number" min="1" max="365" value="{{ old('keep', $keep) }}" class="field"></label>
        <button class="btn">{{ __('portal.save') }}</button>
        <p class="w-full text-xs text-slate-500">{{ __('portal.backups.keep_help') }}</p>
    </form>
</x-layouts.admin>
