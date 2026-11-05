{{-- Outdated routers of one site (decision 2026-11-05, v0.30.0): per router what is outdated or since when it is
     unreachable, next to it the fetch block for the pages and the command blocks of the outdated state blocks (the
     router script, which carries secrets, as a link to its page). Used by the setup page (router state) and the site
     page. Needs: $site, $attention (RouterFreshness::attention() of the site) and the setup view data ($commands,
     $fetch, $apiAccess, $placeholders, $lineStatus, $cliDisabled, $secretsUrl). --}}
@php($blockCommands = collect($commands)->collapse())
<section id="router-update" class="card mb-6 border-2 border-amber-300" data-router-update>
    <h2 class="mb-2 text-lg font-semibold">{{ __('portal.router_fresh.title') }}</h2>
    <p class="mb-3 text-sm text-slate-600">{{ __('portal.router_fresh.intro') }}</p>
    @if ($cliDisabled)
        <p class="mb-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm font-medium text-amber-900" role="status">{{ __('portal.router_fresh.commands_disabled') }}</p>
    @endif
    @foreach ($attention as $entry)
        @php($router = $entry['router'])
        @php($result = $entry['result'])
        @php($keys = collect($result['blocks'])->flatMap(fn ($block) => \App\Nas\MikrotikRos7Driver::STATE_COMMANDS[$block] ?? [])->unique()->values())
        <div id="router-update-{{ $router->id }}" class="mb-4 rounded border border-slate-200 p-3" data-router-outdated="{{ $router->id }}">
            <p class="mb-1 font-medium">{{ $router->name }} ({{ $router->nas_ip }})</p>
            <ul class="mb-2 list-disc pl-6 text-sm">
                @if ($result['pages'] !== [])
                    <li data-outdated-pages="{{ implode(',', $result['pages']) }}">{{ __('portal.router_fresh.pages', ['pages' => \App\Nas\RouterNotifier::files($result['pages'])]) }}</li>
                @endif
                @if ($result['blocks'] !== [])
                    <li data-outdated-blocks="{{ implode(',', $result['blocks']) }}">{{ __('portal.router_fresh.blocks', ['blocks' => collect($result['blocks'])->map(fn ($b) => __('portal.router_api.blocks.'.$b))->implode(', ')]) }}</li>
                @endif
                @if ($result['unreachable'])
                    <li class="text-red-700" data-router-unreachable>{{ __('portal.router_fresh.unreachable', ['since' => \App\Support\LocalTime::format($result['unreachableSince']) ?? '?']) }}
                        <span class="text-xs text-slate-600">{{ __('portal.router_fresh.unreachable_help') }}</span></li>
                @endif
            </ul>
            @if ($result['pages'] !== [] && isset($fetch[$router->id]))
                <p class="mb-2 text-xs text-slate-600">{{ __('portal.router_fresh.pages_help') }}</p>
                <x-admin.code :disabled="$cliDisabled" :title="__('portal.router_fresh.pages_command', ['router' => $router->name])" :code="$fetch[$router->id]" :placeholders="$placeholders" />
            @endif
            @if ($keys->isNotEmpty())
                <p class="mb-2 text-xs text-slate-600">{{ __('portal.router_fresh.blocks_help') }}</p>
                @foreach ($keys as $key)
                    @if ($key === 'router')
                        <p class="mb-3"><a href="{{ route('admin.sites.routers.config', [$site, $router]) }}#block-router" class="btn-light" data-outdated-command="router">{{ __('portal.router_fresh.router_script', ['router' => $router->name]) }}</a></p>
                    @elseif ($key === 'api' && $apiAccess->has($router->id))
                        <div data-outdated-command="api"><x-admin.code :title="__('portal.router_api.access_block', ['name' => $router->name])" :code="$apiAccess[$router->id]"
                            :lines="$lineStatus($apiAccess[$router->id], $router->id)" :placeholders="$placeholders" :secrets-url="$secretsUrl" :scope="$router->id" /></div>
                    @elseif ($blockCommands->has($key))
                        <div data-outdated-command="{{ $key }}"><x-admin.code :disabled="$cliDisabled" :title="__('portal.setup.blocks.'.$key)" :code="$blockCommands[$key]"
                            :lines="$lineStatus($blockCommands[$key], $router->id)" :placeholders="$placeholders" :secrets-url="$secretsUrl" /></div>
                    @endif
                @endforeach
            @endif
        </div>
    @endforeach
</section>
