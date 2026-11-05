@php
    $routers = $site->nasClients;
    $networkSaved = $setup !== [];
    $hostsSet = (array) $site->setting('hotspot_hosts', []) !== [];
    $allSeen = $routers->isNotEmpty() && $routers->every(fn ($r) => $r->last_seen_at !== null);
    $allCert = $routers->isNotEmpty() && $routers->every(fn ($r) => $r->cert_fetched_at !== null);
    $allLogin = $routers->isNotEmpty() && $routers->every(fn ($r) => $r->login_page_fetched_at !== null);
    $when = fn ($t) => $t ? $t->diffForHumans() : __('portal.setup.never');
    $field = fn ($values, $key, $default = '') => old($key, $values[$key] ?? $default);
    // Network inputs: saved value, else the value derived from the router state (marked)
    $net = fn ($key, $default = '') => old($key, $setup[$key] ?? ($derived[$key][0] ?? $default));
    $mark = fn ($key) => isset($derived[$key]) ? new \Illuminate\Support\HtmlString('<span class="gp-badge gp-badge-info ml-1 font-normal" data-derived="'.e($key).'" title="'
        .e(__('portal.router_api.derived_from.'.$derived[$key][1], ['router' => $derived[$key][2]])).'">'.e(__('portal.router_api.derived')).'</span>') : '';
@endphp
<x-layouts.admin :title="__('portal.setup.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.setup.title') }}: {{ $site->name }}</h1>
    <p class="mb-2 text-sm text-slate-600">{{ __('portal.setup.intro') }}</p>
    <p class="mb-6"><a href="{{ route('admin.sites.wizard', $site) }}" class="btn-light" data-wizard-link>{{ __('portal.wizard.open') }}</a></p>

    @include('admin.sites.cert-checklist', ['site' => $site, 'verify' => $commands['diagnostics']['verify'] ?? ''])

    {{-- Router state (read-only API): query, comparison, prefill; greys out the commands while missing or stale --}}
    @if ($stateSupported)
    <section id="step-state" class="card mb-6">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold">{{ __('portal.router_api.state_title') }}</h2>
            @if ($routers->contains(fn ($r) => $r->hasApiAccess()))
                <form method="POST" action="{{ route('admin.sites.setup.state', $site) }}">@csrf
                    <button class="btn" data-refresh-state>{{ __('portal.router_api.refresh') }}</button></form>
            @endif
        </div>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.router_api.state_help', ['minutes' => $maxAge]) }}</p>
        @if ($cliDisabled)
            <p class="mb-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm font-medium text-amber-900" role="status" data-cli-disabled-notice>{{ __('portal.router_api.cli_disabled', ['minutes' => $maxAge]) }}</p>
        @endif
        @if ($derived !== [])
            <p class="mb-3 text-sm text-sky-800">{{ __('portal.router_api.derived_help', ['router' => $derivedFrom]) }}</p>
        @endif
        @forelse ($routers as $router)
            @php($st = $states[$router->id])
            <div class="mb-4 rounded border border-slate-200 p-3" data-router-state="{{ $router->id }}">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ $router->name }} ({{ $router->nas_ip }})</span>
                    @if ($router->hasApiAccess())
                        <x-admin.step-status :ok="$st['fresh']" :label="$router->router_state_at ? __('portal.router_api.queried_at', ['when' => $router->router_state_at->diffForHumans()]) : __('portal.router_api.never_queried')" />
                        @if ($st['version']) <span class="text-xs text-slate-500">RouterOS {{ $st['version'] }}</span> @endif
                        @if ($st['queued'])
                            <span class="gp-badge gp-badge-info" role="status" data-state-queued>{{ __('portal.router_api.queued', ['when' => \Illuminate\Support\Carbon::parse($st['queued'])->diffForHumans()]) }}</span>
                            <a href="{{ route('admin.sites.setup', $site) }}#step-state" class="text-sm underline">{{ __('portal.router_api.reload') }}</a>
                        @endif
                        <form method="POST" action="{{ route('admin.sites.setup.router-state', [$site, $router]) }}">@csrf
                            <button class="btn-light">{{ __('portal.router_api.query') }}</button></form>
                    @else
                        <x-admin.step-status :ok="false" :label="__('portal.router_api.not_configured')" />
                        <a href="{{ route('admin.sites.edit', $site) }}#router-api-{{ $router->id }}" class="text-sm underline">{{ __('portal.router_api.configure') }}</a>
                    @endif
                </div>
                @if ($router->router_state_error)
                    <p class="mb-2 text-sm text-red-700" role="alert" data-state-error="{{ $router->router_state_error['kind'] ?? 'protocol' }}">
                        @php($stateKind = in_array($router->router_state_error['kind'] ?? '', \App\Nas\RouterOs\RouterOsException::KINDS, true) ? $router->router_state_error['kind'] : 'protocol')
                        {{ __('portal.router_api.errors.'.($stateKind === 'tls' && $router->api_pin !== null ? 'tls_pinned' : $stateKind)) }}
                        <span class="text-xs text-slate-600">({{ $router->router_state_error['detail'] ?? '' }})</span></p>
                @endif
                @if ($st['compare'])
                    {{-- Card links to the command blocks of this page (the router script and the access block per router) --}}
                    @php($blockHref = fn (string $command) => match ($command) {
                        'router' => route('admin.sites.routers.config', [$site, $router]).'#block-router',
                        'api' => $apiAccess->has($router->id) ? '#block-api-'.$router->id : null,
                        default => '#block-'.$command,
                    })
                    <div class="grid gap-2 md:grid-cols-3">
                        @foreach ($st['compare'] as $block => $items)
                            @include('admin.sites.partials.state-block', ['block' => $block, 'items' => $items, 'router' => $router, 'blockHref' => $blockHref])
                        @endforeach
                    </div>
                @endif
                @php($adopt = collect($st['compare'] ?? [])->flatMap(fn ($items) => collect($items)->pluck('adopt'))->filter()->unique()->implode("\n"))
                @if ($adopt !== '')
                    {{-- One-time adopt commands for objects without comment (never greyed out, never run by the scripts) --}}
                    <div class="mt-2" data-adopt-commands="{{ $router->id }}">
                        <p class="my-2 text-xs text-slate-600">{{ __('portal.router_api.adopt_help') }}</p>
                        <x-admin.code :title="__('portal.router_api.adopt_title')" :code="$adopt" />
                    </div>
                @endif
                @if ($apiAccess->has($router->id))
                    <details class="mt-2">
                        <summary class="cursor-pointer text-sm">{{ __('portal.router_api.access_commands') }}</summary>
                        <p class="my-2 text-xs text-slate-500">{{ __('portal.router_api.access_help') }}</p>
                        <x-admin.code :anchor="'block-api-'.$router->id" :title="__('portal.router_api.access_block', ['name' => $router->name])" :code="$apiAccess[$router->id]"
                            :lines="$lineStatus($apiAccess[$router->id], $router->id)" :placeholders="$placeholders" :secrets-url="$secretsUrl" :scope="$router->id" />
                    </details>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-500">{{ __('portal.setup.no_routers') }}</p>
        @endforelse
    </section>
    @endif

    {{-- 1. RouterOS container (optional) --}}
    <details id="step-container" class="card mb-6" @if ($errors->hasAny(['veth_address', 'veth_gateway', 'disk', 'image'])) open @endif>
        <summary class="cursor-pointer text-lg font-semibold">1. {{ __('portal.setup.container.title') }}
            <x-admin.step-status :ok="false" :label="__('portal.setup.optional')" /></summary>
        <p class="my-3 text-sm text-slate-600">{{ __('portal.setup.container.help') }}</p>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.container.docker') }}</p>
        {{-- Hint only (router state): no container package, or device mode without containers --}}
        @foreach ($containerProblems as $routerId => $problem)
            <p class="mb-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm font-medium text-amber-900" role="status" data-container-problem="{{ $problem }}">{{ __('portal.setup.container.problem.'.$problem, ['router' => $routers->firstWhere('id', $routerId)?->name]) }}</p>
        @endforeach
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
        <x-admin.code anchor="block-device_mode" :disabled="$cliDisabled" :title="__('portal.setup.blocks.device_mode')" :code="$commands['container']['device_mode']" :placeholders="$placeholders" :lines="$lineStatus($commands['container']['device_mode'])" />
        <x-admin.code anchor="block-container_network" :disabled="$cliDisabled" :title="__('portal.setup.blocks.container_network')" :code="$commands['container']['container_network']" :placeholders="$placeholders" :lines="$lineStatus($commands['container']['container_network'])" />
        <x-admin.code anchor="block-container_add" :disabled="$cliDisabled" :title="__('portal.setup.blocks.container_add')" :code="$commands['container']['container_add']" :placeholders="$placeholders" :lines="$lineStatus($commands['container']['container_add'])" />
        <p class="text-sm text-slate-600">{{ __('portal.setup.container.registry') }}</p>
    </details>

    {{-- 2. Guest network --}}
    <section id="step-network" class="card mb-6">
        <h2 class="mb-2 text-lg font-semibold">2. {{ __('portal.setup.network.title') }}
            <x-admin.step-status :ok="$networkSaved" :label="$networkSaved ? __('portal.setup.saved') : __('portal.setup.missing')" /></h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.network.help') }}</p>
        <form method="POST" action="{{ route('admin.sites.setup.network', $site) }}" class="mb-4 grid gap-3 md:grid-cols-4">
            @csrf
            <label class="label">{{ __('portal.setup.network.vlan_id') }}{{ $mark('vlan_id') }}
                <input name="vlan_id" type="number" min="1" max="4094" value="{{ $net('vlan_id') }}" placeholder="30" required class="field"></label>
            <label class="label md:col-span-2">{{ __('portal.setup.network.bridge') }}{{ $mark('bridge') }}
                <input name="bridge" value="{{ $net('bridge') }}" placeholder="bridge1" required class="field">
                <span class="text-xs text-slate-500">{{ __('portal.setup.network.bridge_help') }}</span></label>
            <label class="label">{{ __('portal.setup.network.interface') }}{{ $mark('interface') }}
                <input name="interface" value="{{ $net('interface') }}" placeholder="vlan-guest" required class="field"></label>
            <label class="label">{{ __('portal.setup.network.ssid') }}{{ $mark('ssid') }}
                <input name="ssid" value="{{ $net('ssid') }}" placeholder="Guest-WiFi" required class="field"></label>
            {{-- Never rendered back in clear: empty keeps the stored password, the checkbox removes it. Every control has
                 its own label (a label around all of them gave the checkboxes no name of their own). --}}
            <div class="label" data-wifi-password-group>
                <label class="flex flex-col gap-1">{{ __('portal.setup.network.wifi_password') }}
                    <input name="wifi_password" type="password" autocomplete="new-password" minlength="8" maxlength="63" class="field" aria-describedby="wifi-password-help"
                        @if ($wifiPasswordSet) placeholder="••••••••" @endif></label>
                <span id="wifi-password-help" class="text-xs text-slate-500">{{ $wifiPasswordSet ? __('portal.setup.network.wifi_password_stored').' ' : '' }}{{ __('portal.setup.network.wifi_password_help') }}</span>
                @if ($wifiPasswordSet)
                    <label class="text-xs"><input type="checkbox" name="wifi_password_clear" value="1"> {{ __('portal.setup.network.wifi_password_clear') }}</label>
                @endif
                <label class="text-xs"><input type="checkbox" name="wifi_password_shared" value="1" aria-describedby="wifi-password-shared-help" @checked($wifiPasswordShared)> {{ __('portal.setup.network.wifi_password_shared') }}</label>
                <span id="wifi-password-shared-help" class="text-xs text-slate-500">{{ __('portal.setup.network.wifi_password_shared_help') }}</span>
                @if ($wifiPasswordUnavailable)
                    <span class="text-xs text-amber-800">{{ __('portal.setup.network.wifi_password_unavailable') }}</span>
                @endif
            </div>
            <label class="label">{{ __('portal.setup.network.subnet') }}{{ $mark('subnet') }}
                <input name="subnet" value="{{ $net('subnet') }}" placeholder="10.30.0.0/24" required class="field"></label>
            <label class="label">{{ __('portal.setup.network.gateway') }}{{ $mark('gateway') }}
                <input name="gateway" value="{{ $net('gateway') }}" placeholder="10.30.0.1" required class="field"></label>
            <label class="label">{{ __('portal.setup.network.pool') }}{{ $mark('pool') }}
                <input name="pool" value="{{ $net('pool') }}" placeholder="10.30.0.10-10.30.0.250" required class="field"></label>
            @include('admin.sites.partials.wifi-modes', ['selectedModes' => array_values(array_filter((array) old('wifi_modes', $wifiModes), 'is_string'))])
            <label class="label">{{ __('portal.setup.network.html_directory') }}{{ $mark('html_directory') }}
                <select name="html_directory" class="field">
                    @foreach (\App\Nas\MikrotikRos7Driver::HTML_DIRECTORIES as $dir)
                        <option value="{{ $dir }}" @selected($net('html_directory', 'hotspot') === $dir)>{{ $dir }}</option>
                    @endforeach
                </select>
                <span class="text-xs text-slate-500">{{ __('portal.setup.network.html_directory_help') }}</span></label>
            <div class="md:col-span-4"><button class="btn">{{ __('portal.save') }}</button></div>
        </form>
        <p class="mb-2 text-sm text-amber-800">{{ __('portal.setup.network.bridge_warning') }}</p>
        <x-admin.code anchor="block-vlan" :disabled="$cliDisabled" :title="__('portal.setup.blocks.vlan')" :code="$commands['network']['vlan']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['vlan'])" />
        <p class="mb-2 text-sm text-slate-600">{{ __('portal.setup.network.vlan_filtering_help') }}</p>
        <x-admin.code anchor="block-vlan_filtering" :disabled="$cliDisabled" :title="__('portal.setup.blocks.vlan_filtering')" :code="$commands['network']['vlan_filtering']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['vlan_filtering'])" />
        {{-- Clean-up of the previous Wi-Fi mode per router (router state: own objects of modes no longer selected) --}}
        @foreach ($wifiLeftovers as $routerId => $leftoverScript)
            @php($leftoverRouter = $routers->firstWhere('id', $routerId))
            <div class="mb-4 rounded border border-amber-300 bg-amber-50 p-3" data-wifi-leftover="{{ $routerId }}">
                <h3 class="mb-1 font-semibold text-amber-900">{{ __('portal.setup.network.wifi_leftover_title', ['router' => $leftoverRouter?->name]) }}</h3>
                <p class="mb-2 text-sm text-amber-900" role="status">{{ __('portal.setup.network.wifi_leftover_help', ['router' => $leftoverRouter?->name]) }}</p>
                <x-admin.code :title="__('portal.setup.network.wifi_leftover_title', ['router' => $leftoverRouter?->name])" :code="$leftoverScript" />
            </div>
        @endforeach
        <x-admin.code anchor="block-wifi" :disabled="$cliDisabled" :title="__('portal.setup.blocks.wifi')" :code="$commands['network']['wifi']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['wifi'])" :secrets-url="$secretsUrl" />
        @if (array_intersect($wifiModes, \App\Models\Site::CAPSMAN_MODES) !== []) <p class="mb-4 text-sm text-amber-800">{{ __('portal.setup.network.slaves_help') }}</p> @endif
        {{-- Provisioning editor per router (CAPsMAN rules from the stored state; script preview, nothing stored) --}}
        @foreach ($routers as $router)
            @if (! empty($provisioning[$router->id]))
                @include('admin.sites.partials.provisioning-editor', ['router' => $router, 'packages' => $provisioning[$router->id]])
            @endif
        @endforeach
        <x-admin.code anchor="block-dhcp" :disabled="$cliDisabled" :title="__('portal.setup.blocks.dhcp')" :code="$commands['network']['dhcp']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['dhcp'])" />
        <x-admin.code anchor="block-captive_option" :disabled="$cliDisabled" :title="__('portal.setup.blocks.captive_option')" :code="$commands['network']['captive_option']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['captive_option'])" />
        <p class="mb-4 text-sm text-slate-600">{{ __('portal.setup.network.captive_help') }}</p>
        <x-admin.code anchor="block-firewall" :disabled="$cliDisabled" :title="__('portal.setup.blocks.firewall')" :code="$commands['network']['firewall']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['firewall'])" />
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
        <x-admin.code anchor="block-dns" :disabled="$cliDisabled" :title="__('portal.setup.blocks.dns')" :code="$commands['hotspot']['dns']" :placeholders="$placeholders" :lines="$lineStatus($commands['hotspot']['dns'])" />
        <x-admin.code anchor="block-hotspot" :disabled="$cliDisabled" :title="__('portal.setup.blocks.hotspot')" :code="$commands['hotspot']['hotspot']" :placeholders="$placeholders" :lines="$lineStatus($commands['hotspot']['hotspot'])" />
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

    {{-- 5. Hotspot pages --}}
    <section id="step-login" class="card mb-6">
        <h2 class="mb-2 text-lg font-semibold">5. {{ __('portal.setup.login.title') }}
            <x-admin.step-status :ok="$allLogin" :label="$allLogin ? __('portal.setup.done') : __('portal.setup.open')" /></h2>
        <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.login.help') }}</p>
        @foreach ($routers as $router)
            <x-admin.code :disabled="$cliDisabled" :title="$router->name.' ('.$router->nas_ip.')'" :code="$fetch[$router->id]" :placeholders="$placeholders" :lines="$lineStatus($fetch[$router->id])" />
        @endforeach
        <p class="flex flex-wrap gap-2 text-sm"><x-admin.hotspot-pages :site="$site" /></p>
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
        <x-admin.code :disabled="$cliDisabled" :code="$commands['diagnostics']['diagnostics']" :placeholders="$placeholders" />
    </section>

    {{-- 8. Uninstall --}}
    <details id="step-uninstall" class="card mb-6">
        <summary class="cursor-pointer text-lg font-semibold">8. {{ __('portal.setup.uninstall.title') }}</summary>
        <p class="my-3 text-sm text-slate-600">{{ __('portal.setup.uninstall.help') }}</p>
        <x-admin.code anchor="block-uninstall" :disabled="$cliDisabled" :title="__('portal.setup.blocks.uninstall')" :code="$commands['uninstall']['uninstall']" :placeholders="$placeholders" :lines="$lineStatus($commands['uninstall']['uninstall'], null, true)" />
        <x-admin.code anchor="block-uninstall_container" :disabled="$cliDisabled" :title="__('portal.setup.blocks.uninstall_container')" :code="$commands['uninstall']['uninstall_container']" :placeholders="$placeholders" :lines="$lineStatus($commands['uninstall']['uninstall_container'], null, true)" />
        <x-admin.code anchor="block-uninstall_network" :disabled="$cliDisabled" :title="__('portal.setup.blocks.uninstall_network')" :code="$commands['uninstall']['uninstall_network']" :placeholders="$placeholders" :lines="$lineStatus($commands['uninstall']['uninstall_network'], null, true)" />
        <p class="text-sm text-amber-800">{{ __('portal.setup.uninstall.portal_hint') }}</p>
    </details>

    <p><a href="{{ route('admin.sites.edit', $site) }}" class="btn-light">{{ __('portal.back') }}</a></p>
</x-layouts.admin>
