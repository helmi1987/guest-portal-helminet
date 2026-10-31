@php
    $routers = $site->nasClients;
    $networkSaved = $setup !== [];
    $hostsSet = (array) $site->setting('hotspot_hosts', []) !== [];
    $allSeen = $routers->isNotEmpty() && $routers->every(fn ($r) => $r->last_seen_at !== null);
    $allCert = $routers->isNotEmpty() && $routers->every(fn ($r) => $r->cert_fetched_at !== null);
    $allLogin = $routers->isNotEmpty() && $routers->every(fn ($r) => $r->login_page_fetched_at !== null);
    $when = fn ($t) => $t ? $t->diffForHumans() : __('portal.setup.never');
    $field = fn ($values, $key, $default = '') => old($key, $values[$key] ?? $default);
@endphp
<x-layouts.admin :title="__('portal.setup.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.setup.title') }}: {{ $site->name }}</h1>
    <p class="mb-6 text-sm text-slate-600">{{ __('portal.setup.intro') }}</p>

    @include('admin.sites.cert-checklist', ['site' => $site, 'verify' => $commands['diagnostics']['verify'] ?? ''])

    {{-- 1. RouterOS container (optional) --}}
    <details id="step-container" class="card mb-6" @if ($errors->hasAny(['veth_address', 'veth_gateway', 'disk', 'image'])) open @endif>
        <summary class="cursor-pointer text-lg font-semibold">1. {{ __('portal.setup.container.title') }}
            <x-admin.step-status :ok="false" :label="__('portal.setup.optional')" /></summary>
        <p class="my-3 text-sm text-slate-600">{{ __('portal.setup.container.help') }}</p>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.container.docker') }}</p>
        <form method="POST" action="{{ route('admin.sites.setup.container', $site) }}" class="mb-4 grid gap-3 md:grid-cols-3">
            @csrf
            <label class="label">{{ __('portal.setup.container.veth_address') }}
                <input name="veth_address" value="{{ $field($container, 'veth_address') }}" placeholder="172.17.0.2/24" required class="field"></label>
            <label class="label">{{ __('portal.setup.container.veth_gateway') }}
                <input name="veth_gateway" value="{{ $field($container, 'veth_gateway') }}" placeholder="172.17.0.1" required class="field"></label>
            <label class="label">{{ __('portal.setup.container.bridge') }}
                <input name="bridge" value="{{ $field($container, 'bridge') }}" placeholder="containers" required class="field"></label>
            <label class="label">{{ __('portal.setup.container.disk') }}
                <input name="disk" value="{{ $field($container, 'disk') }}" placeholder="usb1" required class="field"></label>
            <label class="label md:col-span-2">{{ __('portal.setup.container.image') }}
                <input name="image" value="{{ $field($container, 'image') }}" placeholder="ghcr.io/OWNER/REPO:latest" required class="field"></label>
            <div class="md:col-span-3"><button class="btn">{{ __('portal.save') }}</button></div>
        </form>
        <x-admin.code :title="__('portal.setup.blocks.device_mode')" :code="$commands['container']['device_mode']" />
        <x-admin.code :title="__('portal.setup.blocks.container_network')" :code="$commands['container']['container_network']" />
        <x-admin.code :title="__('portal.setup.blocks.container_add')" :code="$commands['container']['container_add']" />
        <p class="text-sm text-slate-600">{{ __('portal.setup.container.registry') }}</p>
    </details>

    {{-- 2. Guest network --}}
    <section id="step-network" class="card mb-6">
        <h2 class="mb-2 text-lg font-semibold">2. {{ __('portal.setup.network.title') }}
            <x-admin.step-status :ok="$networkSaved" :label="$networkSaved ? __('portal.setup.saved') : __('portal.setup.missing')" /></h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.network.help') }}</p>
        <form method="POST" action="{{ route('admin.sites.setup.network', $site) }}" class="mb-4 grid gap-3 md:grid-cols-4">
            @csrf
            <label class="label">{{ __('portal.setup.network.vlan_id') }}
                <input name="vlan_id" type="number" min="1" max="4094" value="{{ $field($setup, 'vlan_id') }}" placeholder="30" required class="field"></label>
            <label class="label md:col-span-2">{{ __('portal.setup.network.bridge') }}
                <input name="bridge" value="{{ $field($setup, 'bridge') }}" placeholder="bridge1" required class="field">
                <span class="text-xs text-slate-500">{{ __('portal.setup.network.bridge_help') }}</span></label>
            <label class="label">{{ __('portal.setup.network.interface') }}
                <input name="interface" value="{{ $field($setup, 'interface') }}" placeholder="vlan30-guest" required class="field"></label>
            <label class="label">{{ __('portal.setup.network.ssid') }}
                <input name="ssid" value="{{ $field($setup, 'ssid') }}" placeholder="Guest-WiFi" required class="field"></label>
            {{-- Never rendered back in clear: empty keeps the stored password, the checkbox removes it --}}
            <label class="label">{{ __('portal.setup.network.wifi_password') }}
                <input name="wifi_password" type="password" autocomplete="new-password" minlength="8" maxlength="63" class="field"
                    @if ($wifiPasswordSet) placeholder="••••••••" @endif>
                <span class="text-xs text-slate-500">{{ $wifiPasswordSet ? __('portal.setup.network.wifi_password_stored').' ' : '' }}{{ __('portal.setup.network.wifi_password_help') }}</span>
                @if ($wifiPasswordSet)
                    <span class="text-xs"><input type="checkbox" name="wifi_password_clear" value="1"> {{ __('portal.setup.network.wifi_password_clear') }}</span>
                @endif
            </label>
            <label class="label">{{ __('portal.setup.network.subnet') }}
                <input name="subnet" value="{{ $field($setup, 'subnet') }}" placeholder="10.30.0.0/24" required class="field"></label>
            <label class="label">{{ __('portal.setup.network.gateway') }}
                <input name="gateway" value="{{ $field($setup, 'gateway') }}" placeholder="10.30.0.1" required class="field"></label>
            <label class="label">{{ __('portal.setup.network.pool') }}
                <input name="pool" value="{{ $field($setup, 'pool') }}" placeholder="10.30.0.10-10.30.0.250" required class="field"></label>
            <label class="label">{{ __('portal.setup.network.wifi_mode') }}
                <select name="wifi_mode" class="field">
                    @foreach (['capsman2', 'capsman1', 'local'] as $mode)
                        <option value="{{ $mode }}" @selected($field($setup, 'wifi_mode', 'capsman2') === $mode)>{{ __('portal.setup.network.modes.'.$mode) }}</option>
                    @endforeach
                </select></label>
            <label class="label">{{ __('portal.setup.network.html_directory') }}
                <select name="html_directory" class="field">
                    @foreach (\App\Nas\MikrotikRos7Driver::HTML_DIRECTORIES as $dir)
                        <option value="{{ $dir }}" @selected($field($setup, 'html_directory', 'hotspot') === $dir)>{{ $dir }}</option>
                    @endforeach
                </select>
                <span class="text-xs text-slate-500">{{ __('portal.setup.network.html_directory_help') }}</span></label>
            <div class="md:col-span-4"><button class="btn">{{ __('portal.save') }}</button></div>
        </form>
        <p class="mb-2 text-sm text-amber-800">{{ __('portal.setup.network.bridge_warning') }}</p>
        <x-admin.code :title="__('portal.setup.blocks.vlan')" :code="$commands['network']['vlan']" />
        <p class="mb-2 text-sm text-slate-600">{{ __('portal.setup.network.vlan_filtering_help') }}</p>
        <x-admin.code :title="__('portal.setup.blocks.vlan_filtering')" :code="$commands['network']['vlan_filtering']" />
        <x-admin.code :title="__('portal.setup.blocks.wifi')" :code="$commands['network']['wifi']" />
        @if (($setup['wifi_mode'] ?? 'capsman2') !== 'local') <p class="mb-4 text-sm text-amber-800">{{ __('portal.setup.network.slaves_help') }}</p> @endif
        <x-admin.code :title="__('portal.setup.blocks.dhcp')" :code="$commands['network']['dhcp']" />
        <x-admin.code :title="__('portal.setup.blocks.captive_option')" :code="$commands['network']['captive_option']" />
        <p class="mb-4 text-sm text-slate-600">{{ __('portal.setup.network.captive_help') }}</p>
        <x-admin.code :title="__('portal.setup.blocks.firewall')" :code="$commands['network']['firewall']" />
        <p class="text-sm text-slate-600">{{ __('portal.setup.network.guide') }}</p>
    </section>

    {{-- 3. Hotspot --}}
    <section id="step-hotspot" class="card mb-6">
        <h2 class="mb-2 text-lg font-semibold">3. {{ __('portal.setup.hotspot.title') }}
            <x-admin.step-status :ok="$hostsSet" :label="$hostsSet ? __('portal.setup.saved') : __('portal.setup.missing')" /></h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.hotspot.help') }}
            @unless ($hostsSet) <a href="{{ route('admin.sites.edit', $site) }}" class="underline">{{ __('portal.sites.hotspot_hosts') }}</a> @endunless</p>
        @php($hotspotHost = ((array) $site->setting('hotspot_hosts', []))[0] ?? '<HOTSPOT_DNS_NAME>')
        @php($portalHostName = parse_url((string) ($site->setting('portal_url') ?: config('app.url')), PHP_URL_HOST) ?: '<PORTAL_HOST>')
        <p class="mb-1 text-sm text-slate-600">{{ __('portal.setup.hotspot.dns_entries') }}</p>
        <ul class="mb-3 list-disc pl-6 text-sm">
            <li><code>{{ $hotspotHost }}</code> &rarr; <code>{{ $setup['gateway'] ?? '<HOTSPOT_GATEWAY_IP>' }}</code> ({{ __('portal.setup.hotspot.dns_hotspot') }})</li>
            <li><code>{{ $portalHostName }}</code> &rarr; <code>{{ $site->setting('portal_address') ?: ($site->setting('radius_address') ?: '<INSTANCE_IP>') }}</code> ({{ __('portal.setup.hotspot.dns_portal') }})</li>
        </ul>
        @if ($portalAddressProblem)
            <p class="mb-3 text-sm font-semibold text-amber-800">{{ __('portal.setup.hotspot.portal_address_warning') }} {{ $portalAddressProblem }}
                <a href="{{ route('admin.sites.edit', $site) }}" class="underline">{{ __('portal.sites.portal_address') }}</a></p>
        @endif
        <x-admin.code :title="__('portal.setup.blocks.dns')" :code="$commands['hotspot']['dns']" />
        <x-admin.code :title="__('portal.setup.blocks.hotspot')" :code="$commands['hotspot']['hotspot']" />
        <p class="text-sm text-amber-800">{{ __('portal.setup.hotspot.captive_hosts') }}</p>
    </section>

    {{-- 4. RADIUS + router certificate --}}
    <section id="step-radius" class="card mb-6">
        <h2 class="mb-2 text-lg font-semibold">4. {{ __('portal.setup.radius.title') }}
            <x-admin.step-status :ok="$allCert" :label="$allCert ? __('portal.setup.done') : __('portal.setup.open')" /></h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.radius.help') }}</p>
        @forelse ($routers as $router)
            <p class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('admin.sites.routers.config', [$site, $router]) }}" class="btn-light">{{ __('portal.sites.config') }}: {{ $router->name }}</a>
                <x-admin.step-status :ok="$router->cert_fetched_at !== null" :label="__('portal.setup.cert_fetched').': '.$when($router->cert_fetched_at)" />
            </p>
        @empty
            <p class="text-sm text-slate-500">{{ __('portal.setup.no_routers') }} <a href="{{ route('admin.sites.edit', $site) }}" class="underline">{{ __('portal.sites.add_router') }}</a></p>
        @endforelse
    </section>

    {{-- 5. login.html --}}
    <section id="step-login" class="card mb-6">
        <h2 class="mb-2 text-lg font-semibold">5. {{ __('portal.setup.login.title') }}
            <x-admin.step-status :ok="$allLogin" :label="$allLogin ? __('portal.setup.done') : __('portal.setup.open')" /></h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.login.help') }}</p>
        @foreach ($routers as $router)
            <x-admin.code :title="$router->name.' ('.$router->nas_ip.')'" :code="$fetch[$router->id]" />
        @endforeach
        <p class="text-sm"><a href="{{ route('admin.sites.login-page', $site) }}" class="btn-light">{{ __('portal.sites.download_login') }}</a></p>
    </section>

    {{-- 6. Check --}}
    <section id="step-check" class="card mb-6">
        <h2 class="mb-2 text-lg font-semibold">6. {{ __('portal.setup.check.title') }}
            <x-admin.step-status :ok="$allSeen" :label="$allSeen ? __('portal.setup.done') : __('portal.setup.open')" /></h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.check.help') }}</p>
        <table class="data-table">
            <thead><tr><th>{{ __('portal.sites.name') }}</th><th>{{ __('portal.sites.nas_ip') }}</th><th>{{ __('portal.setup.last_seen') }}</th><th>{{ __('portal.setup.cert_fetched') }}</th><th>{{ __('portal.setup.login_fetched') }}</th></tr></thead>
            <tbody>
                @forelse ($routers as $router)
                    <tr>
                        <td>{{ $router->name }}</td>
                        <td>{{ $router->nas_ip }}</td>
                        <td><x-admin.step-status :ok="$router->last_seen_at !== null" :label="$when($router->last_seen_at)" /></td>
                        <td><x-admin.step-status :ok="$router->cert_fetched_at !== null" :label="$when($router->cert_fetched_at)" /></td>
                        <td><x-admin.step-status :ok="$router->login_page_fetched_at !== null" :label="$when($router->login_page_fetched_at)" /></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-slate-500">{{ __('portal.setup.no_routers') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- 7. Diagnostics --}}
    <section id="step-diagnostics" class="card mb-6">
        <h2 class="mb-2 text-lg font-semibold">7. {{ __('portal.setup.diagnostics.title') }}</h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.diagnostics.help') }}</p>
        <x-admin.code :code="$commands['diagnostics']['diagnostics']" />
    </section>

    {{-- 8. Uninstall --}}
    <details id="step-uninstall" class="card mb-6">
        <summary class="cursor-pointer text-lg font-semibold">8. {{ __('portal.setup.uninstall.title') }}</summary>
        <p class="my-3 text-sm text-slate-600">{{ __('portal.setup.uninstall.help') }}</p>
        <x-admin.code :title="__('portal.setup.blocks.uninstall')" :code="$commands['uninstall']['uninstall']" />
        <x-admin.code :title="__('portal.setup.blocks.uninstall_container')" :code="$commands['uninstall']['uninstall_container']" />
        <x-admin.code :title="__('portal.setup.blocks.uninstall_network')" :code="$commands['uninstall']['uninstall_network']" />
        <p class="text-sm text-amber-800">{{ __('portal.setup.uninstall.portal_hint') }}</p>
    </details>

    <p><a href="{{ route('admin.sites.edit', $site) }}" class="btn-light">{{ __('portal.back') }}</a></p>
</x-layouts.admin>
