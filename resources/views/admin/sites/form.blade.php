<x-layouts.admin :title="$site->exists ? $site->name : __('portal.sites.create')">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ $site->exists ? $site->name : __('portal.sites.create') }}</h1>
        @if ($site->exists) <a href="{{ route('admin.sites.setup', $site) }}" class="btn">{{ __('portal.setup.open_assistant') }}</a> @endif
    </div>

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
            <input name="portal_url" value="{{ old('portal_url', $site->setting('portal_url')) }}" placeholder="{{ config('app.url') }}" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.sites.portal_url_help') }}</span></label>
        <label class="label">{{ __('portal.sites.radius_address') }}
            <input name="radius_address" value="{{ old('radius_address', $site->setting('radius_address')) }}" placeholder="10.0.0.10" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.sites.radius_address_help') }}</span></label>
        <label class="label">{{ __('portal.sites.portal_address') }}
            <input name="portal_address" value="{{ old('portal_address', $site->setting('portal_address')) }}" placeholder="10.0.0.10" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.sites.portal_address_help') }}</span></label>
        @if ($instances->count() > 1)
            <label class="label">{{ __('portal.sites.home_instance') }}
                <select name="home_instance_id" class="field">
                    @foreach ($instances as $id => $name)
                        <option value="{{ $id }}" @selected(old('home_instance_id', $site->home_instance_id ?? app(\App\Support\LocalInstance::class)->id()) === $id)>{{ $name }}</option>
                    @endforeach
                </select>
                <span class="text-xs text-slate-500">{{ __('portal.sites.home_instance_help') }}</span></label>
        @endif
        <label class="label md:col-span-2">{{ __('portal.sites.hotspot_hosts') }}
            <input name="hotspot_hosts" value="{{ old('hotspot_hosts', implode(', ', (array) $site->setting('hotspot_hosts', []))) }}" placeholder="hotspot-a.guest.example.com" class="field">
            <span class="text-xs text-slate-500">{{ __('portal.sites.hotspot_hosts_help') }}</span></label>
        <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
    </form>

    @if ($site->exists)
        @include('admin.sites.cert-checklist', ['site' => $site, 'verify' => $site->driver()->setupCommands($site, [])['diagnostics']['verify'] ?? ''])
    @endif

    @if ($site->exists && ! $site->isServedHere())
        <section class="card mb-6 flex flex-wrap items-center justify-between gap-4 border-2 border-amber-300">
            <p class="text-sm">{{ __('portal.sites.served_elsewhere', ['name' => \App\Models\Instance::withTrashed()->find($site->home_instance_id)?->name ?? $site->home_instance_id]) }}</p>
            <form method="POST" action="{{ route('admin.sites.take-over', $site) }}" onsubmit="return confirm(@js(__('portal.sites.take_over_confirm')))">
                @csrf <button class="btn-danger">{{ __('portal.sites.take_over') }}</button></form>
        </section>
    @endif

    @if ($site->exists)
        @php($siteCode = \App\Services\SiteCodeManager::current($site))
        <section class="card mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">{{ __('portal.site_codes.title') }}</h2>
                <p class="text-sm">@if ($siteCode)<span class="font-mono text-xl tracking-widest">{{ $siteCode->code }}</span> · {{ \App\Support\Duration::format($siteCode->duration_s) }}@else<span class="text-slate-500">{{ __('portal.site_codes.none_active') }}</span>@endif</p>
            </div>
            <a href="{{ route('admin.site-codes.show', $site) }}" class="btn-light">{{ __('portal.site_codes.manage') }}</a>
        </section>

        @include('admin.branding.site', ['overrides' => \App\Branding\BrandingSettings::siteOverrides($site),
            'global' => \App\Branding\BrandingSettings::global()])

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
                                <form method="POST" action="{{ route('admin.sites.routers.secret', [$site, $router]) }}" onsubmit="return confirm(@js(__('portal.sites.regenerate_secret_confirm')))">
                                    @csrf <button class="btn-light">{{ __('portal.sites.regenerate_secret') }}</button></form>
                                <form method="POST" action="{{ route('admin.sites.routers.destroy', [$site, $router]) }}" onsubmit="return confirm(@js(__('portal.confirm')))">
                                    @csrf @method('DELETE') <button class="btn-danger">{{ __('portal.delete') }}</button></form>
                            </td>
                        </tr>
                        @php($apiErrors = $errors->getBag('api_'.$router->id))
                        <tr>
                            <td colspan="5">
                                {{-- Read-only router API for the state query of the setup assistant; the password is never rendered, the user name is --}}
                                <details id="router-api-{{ $router->id }}" @if ($apiErrors->any()) open @endif>
                                    <summary class="cursor-pointer text-sm">{{ __('portal.router_api.title') }}:
                                        <x-admin.step-status :ok="$router->hasApiAccess()" :label="$router->hasApiAccess() ? strtoupper($router->api_type === 'api-ssl' ? 'api-ssl' : 'rest').' '.$router->api_host.':'.$router->apiPort() : __('portal.router_api.not_configured')" /></summary>
                                    <p class="my-2 text-xs text-slate-500">{{ __('portal.router_api.help') }}</p>
                                    <form method="POST" action="{{ route('admin.sites.routers.api', [$site, $router]) }}" class="grid items-end gap-3 md:grid-cols-3">
                                        @csrf @method('PUT')
                                        <label class="label">{{ __('portal.router_api.host') }}
                                            <input name="api_host" value="{{ old('api_host', $router->api_host) }}" placeholder="{{ $router->nas_ip }}" class="field"></label>
                                        <label class="label">{{ __('portal.router_api.type') }}
                                            <select name="api_type" class="field">
                                                @foreach (\App\Models\NasClient::API_TYPES as $type)
                                                    <option value="{{ $type }}" @selected(old('api_type', $router->api_type ?? 'rest') === $type)>{{ __('portal.router_api.types.'.$type) }}</option>
                                                @endforeach
                                            </select></label>
                                        <label class="label">{{ __('portal.router_api.port') }}
                                            <input name="api_port" type="number" min="1" max="65535" value="{{ old('api_port', $router->api_port) }}" placeholder="443 / 8729" class="field"></label>
                                        <label class="label">{{ __('portal.router_api.user') }}
                                            <input name="api_user" value="{{ old('api_user', $router->api_user) }}" autocomplete="off" placeholder="guest-portal-ro" class="field"></label>
                                        <label class="label">{{ __('portal.router_api.password') }}
                                            <input name="api_password" type="password" autocomplete="new-password" maxlength="128" class="field"
                                                @if ($router->api_password !== null) placeholder="••••••••" @endif>
                                            @if ($router->api_password !== null)<span class="text-xs text-slate-500">{{ __('portal.router_api.password_stored') }}</span>@endif</label>
                                        <label class="label">{{ __('portal.router_api.pin') }}
                                            <input name="api_pin" value="{{ old('api_pin', $router->api_pin) }}" placeholder="sha256//..." class="field">
                                            <span class="text-xs text-slate-500">{{ __('portal.router_api.pin_help') }}</span></label>
                                        @foreach ($apiErrors->all() as $message)
                                            <p class="text-sm text-red-700 md:col-span-3">{{ $message }}</p>
                                        @endforeach
                                        <div class="flex flex-wrap items-center gap-3 md:col-span-3">
                                            <button class="btn-light">{{ __('portal.save') }}</button>
                                            @if ($router->hasApiAccess() || $router->api_host)
                                                <label class="text-xs"><input type="checkbox" name="api_clear" value="1"> {{ __('portal.router_api.clear') }}</label>
                                            @endif
                                        </div>
                                    </form>
                                </details>
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
