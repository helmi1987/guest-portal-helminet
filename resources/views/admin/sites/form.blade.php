<x-layouts.admin :title="$site->exists ? $site->name : __('portal.sites.create')">
    <h1 class="mb-4 text-2xl font-semibold">{{ $site->exists ? $site->name : __('portal.sites.create') }}</h1>

    <form method="POST" action="{{ $site->exists ? route('admin.sites.update', $site) : route('admin.sites.store') }}" class="card mb-6 grid gap-4 md:grid-cols-2">
        @csrf
        @if ($site->exists) @method('PUT') @endif
        <label class="label">{{ __('portal.sites.name') }}
            <input name="name" value="{{ old('name', $site->name) }}" required class="field"></label>
        <label class="label">{{ __('portal.sites.slug') }}
            <input name="slug" value="{{ old('slug', $site->slug) }}" required pattern="[A-Za-z0-9_\-]+" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.sites.slug_help') }}</span></label>
        <label class="label">{{ __('portal.sites.driver') }}
            <select name="driver" class="field">
                @foreach (\App\Nas\NasDrivers::options() as $key => $label)
                    <option value="{{ $key }}" @selected(old('driver', $site->driver) === $key)>{{ $label }}</option>
                @endforeach
            </select></label>
        <label class="label">{{ __('portal.sites.portal_url') }}
            <input name="portal_url" value="{{ old('portal_url', $site->setting('portal_url')) }}" placeholder="{{ config('app.url') }}" class="field"></label>
        <label class="label">{{ __('portal.sites.radius_address') }}
            <input name="radius_address" value="{{ old('radius_address', $site->setting('radius_address')) }}" placeholder="10.0.0.10" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.sites.radius_address_help') }}</span></label>
        <label class="label">{{ __('portal.sites.portal_address') }}
            <input name="portal_address" value="{{ old('portal_address', $site->setting('portal_address')) }}" placeholder="10.0.0.10" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.sites.portal_address_help') }}</span></label>
        <label class="label md:col-span-2">{{ __('portal.sites.hotspot_hosts') }}
            <input name="hotspot_hosts" value="{{ old('hotspot_hosts', implode(', ', (array) $site->setting('hotspot_hosts', []))) }}" placeholder="hotspot-a.guest.example.com" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.sites.hotspot_hosts_help') }}</span></label>
        <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
    </form>

    @if ($site->exists)
        <section class="card mb-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold">{{ __('portal.sites.routers') }}</h2>
                <a href="{{ route('admin.sites.login-page', $site) }}" class="btn-light">{{ __('portal.sites.download_login') }}</a>
            </div>
            <table class="data-table mb-4">
                <thead><tr><th>{{ __('portal.sites.name') }}</th><th>{{ __('portal.sites.nas_ip') }}</th><th>{{ __('portal.sites.nas_identifier') }}</th><th>{{ __('portal.sites.coa_port') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($site->nasClients as $router)
                        <tr>
                            <td>{{ $router->name }}</td>
                            <td>{{ $router->nas_ip }}</td>
                            <td>{{ $router->nas_identifier ?: '–' }}</td>
                            <td>{{ $router->coa_port }}</td>
                            <td class="flex flex-wrap gap-2">
                                <a href="{{ route('admin.sites.routers.config', [$site, $router]) }}" class="btn-light">{{ __('portal.sites.config') }}</a>
                                <form method="POST" action="{{ route('admin.sites.routers.secret', [$site, $router]) }}" onsubmit="return confirm(@js(__('portal.confirm')))">
                                    @csrf <button class="btn-light">{{ __('portal.sites.regenerate_secret') }}</button></form>
                                <form method="POST" action="{{ route('admin.sites.routers.destroy', [$site, $router]) }}" onsubmit="return confirm(@js(__('portal.confirm')))">
                                    @csrf @method('DELETE') <button class="btn-danger">{{ __('portal.delete') }}</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-slate-500">{{ __('portal.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <form method="POST" action="{{ route('admin.sites.routers.store', $site) }}" class="grid items-end gap-3 md:grid-cols-5">
                @csrf
                <label class="label">{{ __('portal.sites.name') }}<input name="name" required class="field"></label>
                <label class="label">{{ __('portal.sites.nas_ip') }}<input name="nas_ip" required placeholder="10.0.0.1" class="field"></label>
                <label class="label">{{ __('portal.sites.nas_identifier') }}<input name="nas_identifier" class="field"></label>
                <label class="label">{{ __('portal.sites.coa_port') }}<input name="coa_port" type="number" value="3799" required class="field"></label>
                <button class="btn">{{ __('portal.sites.add_router') }}</button>
            </form>
        </section>

        <form method="POST" action="{{ route('admin.sites.destroy', $site) }}" onsubmit="return confirm(@js(__('portal.confirm')))">
            @csrf @method('DELETE') <button class="btn-danger">{{ __('portal.sites.delete') }}</button>
        </form>
    @endif
</x-layouts.admin>
