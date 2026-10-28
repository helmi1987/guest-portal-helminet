<x-layouts.admin :title="__('portal.sites.title')">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('portal.sites.title') }}</h1>
        <a href="{{ route('admin.sites.create') }}" class="btn">{{ __('portal.sites.create') }}</a>
    </div>
    <div class="card">
        <table class="data-table">
            <thead><tr><th>{{ __('portal.sites.name') }}</th><th>{{ __('portal.sites.slug') }}</th><th>{{ __('portal.sites.driver') }}</th><th>{{ __('portal.sites.routers') }}</th></tr></thead>
            <tbody>
                @forelse ($sites as $site)
                    <tr>
                        <td><a href="{{ route('admin.sites.edit', $site) }}" class="underline">{{ $site->name }}</a></td>
                        <td>{{ $site->slug }}</td>
                        <td>{{ $site->driver }}</td>
                        <td>{{ $site->nas_clients_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.admin>
