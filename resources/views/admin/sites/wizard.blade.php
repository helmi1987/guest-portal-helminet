@php
    // Guided wizard (decision 2026-11-02): one setup block per step, data of the setup page (SetupController::viewData)
    $routers = $site->nasClients;
    $keys = array_keys($steps);
    $n = $step['number'];
    $total = count($steps);
    $key = $step['key'];
    $stepUrl = fn (int $number) => route('admin.sites.wizard', ['site' => $site, 'step' => $number]).'#wizard-step';
    $when = fn ($t) => $t ? $t->diffForHumans() : __('portal.setup.never');
    $field = fn ($values, $name, $default = '') => old($name, $values[$name] ?? $default);
    // Network inputs: saved value, else the value derived from the router state (marked), as on the setup page
    $net = fn ($name, $default = '') => old($name, $setup[$name] ?? ($derived[$name][0] ?? $default));
    $mark = fn ($name) => isset($derived[$name]) ? new \Illuminate\Support\HtmlString('<span class="gp-badge gp-badge-info ml-1 font-normal" data-derived="'.e($name).'" title="'
        .e(__('portal.router_api.derived_from.'.$derived[$name][1], ['router' => $derived[$name][2]])).'">'.e(__('portal.router_api.derived')).'</span>') : '';
    $badges = ['verified' => 'gp-badge-ok', 'done' => 'gp-badge-ok', 'manual' => 'gp-badge-info', 'skipped' => 'gp-badge-warn', 'open' => 'gp-badge-neutral', 'unused' => 'gp-badge-info'];
    $labels = array_map(fn ($s) => __('portal.wizard.progress.'.$s), array_combine(array_keys($badges), array_keys($badges)));
    $cfg = [
        // Progress per site in the browser only (localStorage, decision 2026-11-02)
        'storeKey' => 'gp-wizard:'.$site->id,
        'step' => $key,
        'ok' => array_map(fn ($s) => $s['ok'], $steps),
        'ready' => $step['ready'],
        'apiOk' => $step['apiOk'],
        'manual' => $step['manual'],
        'labels' => $labels,
        'badges' => $badges,
    ];
    // State card links: the step holding the command block (the router script page for "router")
    $commandSteps = \App\Http\Controllers\Admin\WizardController::COMMAND_STEPS;
    $blockHref = fn (string $command, $router) => match (true) {
        $command === 'router' => route('admin.sites.routers.config', [$site, $router]).'#block-router',
        isset($steps[$commandSteps[$command] ?? '']) => route('admin.sites.wizard', ['site' => $site, 'step' => $steps[$commandSteps[$command]]['number']])
            .'#block-'.($command === 'api' ? 'api-'.$router->id : $command),
        default => null,
    };
    $hotspotHost = ((array) $site->setting('hotspot_hosts', []))[0] ?? '<HOTSPOT_DNS_NAME>';
    $portalHostName = parse_url((string) ($site->setting('portal_url') ?: config('app.url')), PHP_URL_HOST) ?: '<PORTAL_HOST>';
@endphp
<x-layouts.admin :title="__('portal.wizard.title')">
    @once
    <script>
        // Wizard progress (done / manual / skipped) and the manual confirmations per router: browser only,
        // every storage access guarded (private mode, blocked storage: the page works without it).
        window.gpWizard = function (cfg) {
            return {
                progress: {},
                init() {
                    try { var v = JSON.parse(window.localStorage.getItem(cfg.storeKey) || '{}'); this.progress = v && typeof v === 'object' ? v : {}; }
                    catch (e) { this.progress = {}; }
                },
                save() { try { window.localStorage.setItem(cfg.storeKey, JSON.stringify(this.progress)); } catch (e) { /* not persisted */ } },
                status(step) {
                    if (cfg.ok[step]) return 'verified';
                    var v = this.progress[this.stepKey(step)];
                    return v === 'done' || v === 'manual' || v === 'skipped' || v === 'unused' ? v : 'open';
                },
                label(step) { return cfg.labels[this.status(step)]; },
                badge(step) { return 'gp-badge ' + cfg.badges[this.status(step)]; },
                // Keys are step-name based (never the position), so reordering the steps cannot mix up progress
                stepKey(step) { return 'step:' + step; },
                get unused() { return this.progress[this.stepKey(cfg.step)] === 'unused'; },
                setUnused(on) { if (on) { this.progress[this.stepKey(cfg.step)] = 'unused'; } else if (this.unused) { delete this.progress[this.stepKey(cfg.step)]; } this.save(); },
                manualKey(id) { return 'manual:' + cfg.step + ':' + id; },
                isManual(id) { return this.progress[this.manualKey(id)] === true; },
                setManual(id, on) { if (on) { this.progress[this.manualKey(id)] = true; } else { delete this.progress[this.manualKey(id)]; } this.save(); },
                get canNext() {
                    var self = this;
                    if (this.unused) return true;
                    return cfg.ready && cfg.apiOk && cfg.manual.every(function (id) { return self.isManual(id); });
                },
                leave(how) {
                    var k = this.stepKey(cfg.step);
                    if (how === 'next') { if (!this.unused) { this.progress[k] = cfg.ok[cfg.step] ? 'done' : 'manual'; } }
                    else if (!cfg.ok[cfg.step]) { this.progress[k] = 'skipped'; }
                    this.save();
                },
            };
        };
    </script>
    @endonce

    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.wizard.title') }}: {{ $site->name }}</h1>
    <p class="mb-2 text-sm text-slate-600">{{ __('portal.wizard.intro') }}</p>
    <p class="mb-6 flex flex-wrap gap-2 text-sm">
        <a href="{{ route('admin.sites.setup', $site) }}" class="btn-light">{{ __('portal.wizard.full_page') }}</a>
        <a href="{{ route('admin.sites.setup', $site) }}#step-uninstall" class="btn-light" data-wizard-uninstall>{{ __('portal.wizard.uninstall') }}</a>
    </p>

    <div x-data="gpWizard(@js($cfg))" class="grid gap-6 md:grid-cols-[15rem_minmax(0,1fr)]">
        {{-- Step navigation with status (server: verified by the router state; browser: done / manual / skipped) --}}
        <nav aria-label="{{ __('portal.wizard.steps_nav') }}" class="min-w-0">
            <ol class="flex flex-wrap gap-2 md:flex-col md:gap-1" data-wizard-nav>
                @foreach ($steps as $s)
                    <li>
                        <a href="{{ $stepUrl($s['number']) }}" @if ($s['key'] === $key) aria-current="step" @endif data-wizard-nav-step="{{ $s['key'] }}"
                            @class(['flex flex-wrap items-center gap-2 rounded px-2 py-1 text-sm', 'font-semibold underline' => $s['key'] === $key])>
                            <span>{{ $s['number'] }}. {{ __('portal.wizard.steps.'.$s['key'].'.title') }}</span>
                            <span class="gp-badge {{ $badges[$s['ok'] ? 'verified' : 'open'] }}" x-bind:class="badge(@js($s['key']))" x-text="label(@js($s['key']))"
                                data-step-status="{{ $s['key'] }}">{{ $labels[$s['ok'] ? 'verified' : 'open'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </nav>

        <section id="wizard-step" class="card min-w-0" data-wizard-step="{{ $key }}">
            <p class="mb-1 text-xs text-slate-500">{{ __('portal.wizard.step_of', ['n' => $n, 'total' => $total]) }}</p>
            <h2 class="mb-2 text-lg font-semibold">{{ $n }}. {{ __('portal.wizard.steps.'.$key.'.title') }}
                @if ($step['optional']) <x-admin.step-status :ok="false" :label="__('portal.setup.optional')" /> @endif</h2>
            <p class="mb-4 text-sm text-slate-600">{{ __('portal.wizard.steps.'.$key.'.help') }}</p>
            @if (! $step['ready'])
                <p class="mb-4 rounded border border-amber-300 bg-amber-50 p-2 text-sm font-medium text-amber-900" role="status" data-wizard-not-ready>{{ __('portal.wizard.not_ready.'.$key) }}</p>
            @endif
            @if ($cliDisabled && $key !== 'access' && $step['hasScope'])
                <p class="mb-4 rounded border border-amber-300 bg-amber-50 p-2 text-sm font-medium text-amber-900" role="status" data-cli-disabled-notice>{{ __('portal.router_api.cli_disabled', ['minutes' => $maxAge]) }}</p>
            @endif

            @switch($key)
                @case('access')
                    @forelse ($routers as $router)
                        <div class="mb-4 rounded border border-slate-200 p-3">
                            <p class="mb-2 flex flex-wrap items-center gap-2 text-sm font-medium">{{ $router->name }} ({{ $router->nas_ip }})
                                @unless ($router->hasApiAccess())
                                    <x-admin.step-status :ok="false" :label="__('portal.router_api.not_configured')" />
                                @endunless
                                <a href="{{ route('admin.sites.edit', $site) }}#router-api-{{ $router->id }}" class="font-normal underline">{{ __('portal.router_api.configure') }}</a></p>
                            @if ($apiAccess->has($router->id))
                                <p class="mb-2 text-xs text-slate-500">{{ __('portal.router_api.access_help') }}</p>
                                <x-admin.code :anchor="'block-api-'.$router->id" :title="__('portal.router_api.access_block', ['name' => $router->name])" :code="$apiAccess[$router->id]"
                                    :lines="$lineStatus($apiAccess[$router->id], $router->id)" :placeholders="$placeholders" :secrets-url="$secretsUrl" :scope="$router->id" />
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('portal.setup.no_routers') }} <a href="{{ route('admin.sites.edit', $site) }}" class="underline">{{ __('portal.sites.add_router') }}</a></p>
                    @endforelse
                    @break

                @case('container')
                    <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.container.help') }}</p>
                    <p class="mb-3 text-sm text-slate-600">{{ __('portal.setup.container.docker') }}</p>
                    {{-- Hint only (router state): no container package, or device mode without containers --}}
                    @foreach ($containerProblems as $routerId => $problem)
                        <p class="mb-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm font-medium text-amber-900" role="status" data-container-problem="{{ $problem }}">{{ __('portal.setup.container.problem.'.$problem, ['router' => $routers->firstWhere('id', $routerId)?->name]) }}</p>
                    @endforeach
                    {{-- "Not used" counts as done; kept in the browser like the other progress --}}
                    <fieldset class="mb-4 text-sm" data-wizard-container-choice>
                        <label class="mr-4"><input type="radio" name="container_choice" value="used" x-bind:checked="!unused" x-on:change="setUnused(false)" data-wizard-container-used> {{ __('portal.wizard.container_used') }}</label>
                        <label><input type="radio" name="container_choice" value="unused" x-bind:checked="unused" x-on:change="setUnused(true)" data-wizard-container-unused> {{ __('portal.wizard.container_unused') }}</label>
                    </fieldset>
                    <div x-show="!unused" data-wizard-container-body>
                    <form method="POST" action="{{ route('admin.sites.setup.container', $site) }}" class="mb-4 grid gap-3 md:grid-cols-3" data-wizard-form="container">
                        @csrf
                        <input type="hidden" name="wizard_step" value="{{ $n }}">
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
                        @if ($errors->any())
                            <ul class="md:col-span-3 text-sm text-red-700" role="alert">@foreach ($errors->all() as $message) <li>{{ $message }}</li> @endforeach</ul>
                        @endif
                        <div class="md:col-span-3"><button class="btn">{{ __('portal.save') }}</button></div>
                    </form>
                    @foreach (['device_mode', 'container_network', 'container_add'] as $block)
                        <x-admin.code :anchor="'block-'.$block" :disabled="$cliDisabled" :title="__('portal.setup.blocks.'.$block)" :code="$commands['container'][$block]" :placeholders="$placeholders" :lines="$lineStatus($commands['container'][$block])" />
                    @endforeach
                    <p class="text-sm text-slate-600">{{ __('portal.setup.container.registry') }}</p>
                    </div>
                    @break

                @case('network')
                    @include('admin.sites.partials.wizard-setup-form', ['group' => 'network', 'stepNumber' => $n])
                    <p class="mb-2 text-sm text-amber-800">{{ __('portal.setup.network.bridge_warning') }}</p>
                    <x-admin.code anchor="block-vlan" :disabled="$cliDisabled" :title="__('portal.setup.blocks.vlan')" :code="$commands['network']['vlan']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['vlan'])" />
                    <p class="mb-2 text-sm text-slate-600">{{ __('portal.setup.network.vlan_filtering_help') }}</p>
                    <x-admin.code anchor="block-vlan_filtering" :disabled="$cliDisabled" :title="__('portal.setup.blocks.vlan_filtering')" :code="$commands['network']['vlan_filtering']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['vlan_filtering'])" />
                    <x-admin.code anchor="block-dhcp" :disabled="$cliDisabled" :title="__('portal.setup.blocks.dhcp')" :code="$commands['network']['dhcp']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['dhcp'])" />
                    <p class="text-sm text-slate-600">{{ __('portal.setup.network.guide') }}</p>
                    @break

                @case('wifi')
                    @include('admin.sites.partials.wizard-setup-form', ['group' => 'wifi', 'stepNumber' => $n])
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
                    @foreach ($routers as $router)
                        @if (! empty($provisioning[$router->id]))
                            @include('admin.sites.partials.provisioning-editor', ['router' => $router, 'packages' => $provisioning[$router->id]])
                        @endif
                    @endforeach
                    @break

                @case('firewall')
                    <x-admin.code anchor="block-captive_option" :disabled="$cliDisabled" :title="__('portal.setup.blocks.captive_option')" :code="$commands['network']['captive_option']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['captive_option'])" />
                    <p class="mb-4 text-sm text-slate-600">{{ __('portal.setup.network.captive_help') }}</p>
                    <x-admin.code anchor="block-firewall" :disabled="$cliDisabled" :title="__('portal.setup.blocks.firewall')" :code="$commands['network']['firewall']" :placeholders="$placeholders" :lines="$lineStatus($commands['network']['firewall'])" />
                    @break

                @case('hotspot')
                    @include('admin.sites.partials.wizard-setup-form', ['group' => 'hotspot', 'stepNumber' => $n])
                    <p class="mb-1 text-sm text-slate-600">{{ __('portal.setup.hotspot.help') }}
                        <a href="{{ route('admin.sites.edit', $site) }}" class="underline">{{ __('portal.sites.hotspot_hosts') }}</a></p>
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
                    <p class="mb-2 text-sm text-slate-600">{{ __('portal.wizard.walled_garden') }}</p>
                    <p class="text-sm text-amber-800">{{ __('portal.setup.hotspot.captive_hosts') }}</p>
                    @break

                @case('router')
                    <p class="mb-3 text-sm text-amber-800">{{ __('portal.wizard.router_script_secret') }}</p>
                    @include('admin.sites.cert-checklist', ['site' => $site, 'verify' => $commands['diagnostics']['verify'] ?? ''])
                    @forelse ($routers as $router)
                        <p class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                            <a href="{{ route('admin.sites.routers.config', [$site, $router]) }}" class="btn-light" data-router-script="{{ $router->id }}">{{ __('portal.wizard.router_script', ['name' => $router->name]) }}</a>
                            <x-admin.step-status :ok="$router->cert_fetched_at !== null" :label="__('portal.setup.cert_fetched').': '.$when($router->cert_fetched_at)" />
                        </p>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('portal.setup.no_routers') }}</p>
                    @endforelse
                    <p class="mb-3 mt-4 text-sm">{{ __('portal.wizard.radius_address') }}: <code>{{ $site->setting('radius_address') ?: '<INSTANCE_IP>' }}</code>
                        <a href="{{ route('admin.sites.edit', $site) }}" class="underline">{{ __('portal.router_api.configure') }}</a></p>
                    <div class="mb-4 overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>{{ __('portal.sites.name') }}</th><th>{{ __('portal.sites.nas_ip') }}</th><th>{{ __('portal.setup.last_seen') }}</th></tr></thead>
                            <tbody>
                                @forelse ($routers as $router)
                                    <tr><td>{{ $router->name }}</td><td>{{ $router->nas_ip }}</td>
                                        <td><x-admin.step-status :ok="$router->last_seen_at !== null" :label="$when($router->last_seen_at)" /></td></tr>
                                @empty
                                    <tr><td colspan="3" class="text-slate-500">{{ __('portal.setup.no_routers') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <x-admin.code :disabled="$cliDisabled" :title="__('portal.setup.diagnostics.title')" :code="$commands['diagnostics']['diagnostics']" :placeholders="$placeholders" />
                    @break

                @case('login')
                    @foreach ($routers as $router)
                        <x-admin.code :disabled="$cliDisabled" :title="$router->name.' ('.$router->nas_ip.')'" :code="$fetch[$router->id]" :placeholders="$placeholders" :lines="$lineStatus($fetch[$router->id])" />
                        <p class="mb-4 text-sm"><x-admin.step-status :ok="$router->login_page_fetched_at !== null" :label="__('portal.setup.login_fetched').': '.$when($router->login_page_fetched_at)" /></p>
                    @endforeach
                    <p class="text-sm"><a href="{{ route('admin.sites.login-page', $site) }}" class="btn-light">{{ __('portal.sites.download_login') }}</a></p>
                    @break

                @case('status')
                    <div class="mb-4 overflow-x-auto">
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
                    </div>
                    <p class="mb-2 text-sm text-slate-600">{{ __('portal.setup.check.help') }}</p>
                    <ul class="mb-4 space-y-1 text-sm" data-wizard-summary>
                        @foreach ($steps as $s)
                            @continue($s['key'] === 'status')
                            <li><a href="{{ $stepUrl($s['number']) }}" class="underline">{{ $s['number'] }}. {{ __('portal.wizard.steps.'.$s['key'].'.title') }}</a>
                                <span class="gp-badge {{ $badges[$s['ok'] ? 'verified' : 'open'] }}" x-bind:class="badge(@js($s['key']))" x-text="label(@js($s['key']))">{{ $labels[$s['ok'] ? 'verified' : 'open'] }}</span></li>
                        @endforeach
                    </ul>
                    <p class="text-sm"><a href="{{ route('admin.sites.setup', $site) }}#step-diagnostics" class="underline">{{ __('portal.setup.diagnostics.title') }}</a></p>
                    @break
            @endswitch

            {{-- Check: state query per router (read-only API), shown for this step's objects only --}}
            @if ($step['hasScope'])
                <div class="mt-6 border-t border-slate-200 pt-4" data-wizard-check @if ($key === 'container') x-show="!unused" @endif>
                    <h3 class="mb-1 font-semibold">{{ __('portal.wizard.check_title') }}</h3>
                    <p class="mb-3 text-sm text-slate-600">{{ __('portal.wizard.check_help', ['minutes' => $maxAge]) }}</p>
                    @forelse ($routers as $router)
                        @php($st = $states[$router->id])
                        @php($scoped = $step['scoped'][$router->id])
                        <div class="mb-3 rounded border border-slate-200 p-3" data-wizard-router="{{ $router->id }}">
                            <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-medium">{{ $router->name }} ({{ $router->nas_ip }})</span>
                                @if ($apiRouters->contains('id', $router->id))
                                    <x-admin.step-status :ok="$st['fresh']" :label="$router->router_state_at ? __('portal.router_api.queried_at', ['when' => $router->router_state_at->diffForHumans()]) : __('portal.router_api.never_queried')" />
                                    <form method="POST" action="{{ route('admin.sites.setup.router-state', [$site, $router]) }}">@csrf
                                        <input type="hidden" name="wizard_step" value="{{ $n }}">
                                        <button class="btn-light" data-wizard-query="{{ $router->id }}">{{ __('portal.wizard.check') }}</button></form>
                                    @if ($scoped !== null && $st['fresh'])
                                        <span @class(['gp-badge', 'gp-badge-ok' => \App\Http\Controllers\Admin\WizardController::green($scoped['required']), 'gp-badge-error' => ! \App\Http\Controllers\Admin\WizardController::green($scoped['required'])])
                                            data-wizard-router-ok="{{ \App\Http\Controllers\Admin\WizardController::green($scoped['required']) ? 'yes' : 'no' }}">
                                            {{ \App\Http\Controllers\Admin\WizardController::green($scoped['required']) ? __('portal.wizard.router_ok') : __('portal.wizard.router_open') }}</span>
                                    @endif
                                @else
                                    <x-admin.step-status :ok="false" :label="__('portal.router_api.not_configured')" />
                                    <label class="text-sm"><input type="checkbox" x-bind:checked="isManual(@js((string) $router->id))" x-on:change="setManual(@js((string) $router->id), $event.target.checked)"
                                        data-wizard-manual="{{ $router->id }}"> {{ __('portal.wizard.manual') }}</label>
                                @endif
                            </div>
                            @if ($apiRouters->contains('id', $router->id))
                                @if ($router->router_state_error)
                                    @php($stateKind = in_array($router->router_state_error['kind'] ?? '', \App\Nas\RouterOs\RouterOsException::KINDS, true) ? $router->router_state_error['kind'] : 'protocol')
                                    <p class="mb-2 text-sm text-red-700" role="alert" data-state-error="{{ $stateKind }}">
                                        {{ __('portal.router_api.errors.'.($stateKind === 'tls' && $router->api_pin !== null ? 'tls_pinned' : $stateKind)) }}
                                        <span class="text-xs text-slate-600">({{ $router->router_state_error['detail'] ?? '' }})</span></p>
                                @endif
                                @if ($scoped !== null)
                                    <div class="grid gap-2 md:grid-cols-2">
                                        @foreach ($scoped['required'] as $block => $items)
                                            @include('admin.sites.partials.state-block', ['block' => $block, 'items' => $items, 'router' => $router, 'blockHref' => fn ($command) => $blockHref($command, $router)])
                                        @endforeach
                                    </div>
                                    @if ($scoped['info'] !== [])
                                        <p class="mt-2 text-xs text-slate-600">{{ __('portal.wizard.info_objects') }}</p>
                                        <div class="grid gap-2 md:grid-cols-2" data-wizard-info>
                                            @foreach ($scoped['info'] as $block => $items)
                                                @include('admin.sites.partials.state-block', ['block' => $block, 'items' => $items, 'router' => $router, 'blockHref' => fn ($command) => $blockHref($command, $router)])
                                            @endforeach
                                        </div>
                                    @endif
                                    @php($adopt = collect($scoped['required'])->merge($scoped['info'])->flatMap(fn ($items) => collect($items)->pluck('adopt'))->filter()->unique()->implode("\n"))
                                    @if ($adopt !== '')
                                        <div class="mt-2" data-adopt-commands="{{ $router->id }}">
                                            <p class="my-2 text-xs text-slate-600">{{ __('portal.router_api.adopt_help') }}</p>
                                            <x-admin.code :title="__('portal.router_api.adopt_title')" :code="$adopt" />
                                        </div>
                                    @endif
                                @else
                                    <p class="text-sm text-slate-500">{{ __('portal.router_api.never_queried') }}</p>
                                @endif
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('portal.setup.no_routers') }}</p>
                    @endforelse
                </div>
            @endif

            {{-- Navigation: Next only when verified (or confirmed by hand for routers without API access), else Skip --}}
            <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-4" data-wizard-buttons>
                @if ($n > 1)
                    <a href="{{ $stepUrl($n - 1) }}" class="btn-light" data-wizard-back>{{ __('portal.wizard.back') }}</a>
                @endif
                @if ($key === 'status')
                    <a href="{{ route('admin.sites.edit', $site) }}" class="btn" data-wizard-finish>{{ __('portal.wizard.finish') }}</a>
                @else
                    <a href="{{ $stepUrl($n + 1) }}" class="btn" data-wizard-next x-show="canNext" x-on:click="leave('next')" @unless ($step['ok']) style="display: none" @endunless>{{ __('portal.wizard.next') }}</a>
                    <button type="button" class="btn" disabled aria-disabled="true" data-wizard-next-disabled x-show="!canNext" @if ($step['ok']) style="display: none" @endif
                        title="{{ __('portal.wizard.next_disabled') }}">{{ __('portal.wizard.next') }}</button>
                    <a href="{{ $stepUrl($n + 1) }}" class="btn-light" data-wizard-skip x-show="!canNext" x-on:click="leave('skip')" @if ($step['ok']) style="display: none" @endif>{{ __('portal.wizard.skip') }}</a>
                    <span class="text-xs text-slate-500" x-show="!canNext" @if ($step['ok']) style="display: none" @endif>{{ __('portal.wizard.next_disabled') }}</span>
                @endif
            </div>
        </section>
    </div>
</x-layouts.admin>
