<x-layouts.admin :title="__('portal.admin.dashboard')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.admin.dashboard') }}</h1>
    <p class="text-slate-600">{{ __('portal.admin.welcome', ['name' => auth()->user()->name]) }}</p>
    <p class="mt-2 text-sm text-slate-500">{{ __('portal.admin.not_ready') }}</p>

    {{-- Outdated routers (decision 2026-11-05, v0.30.0): one line per router, the commands are on its router state --}}
    @if ($routerAttention->isNotEmpty())
        <section class="card mt-6 border-2 border-amber-300" data-router-update>
            <h2 class="mb-2 text-lg font-semibold">{{ __('portal.router_fresh.title') }}</h2>
            <p class="mb-3 text-sm text-slate-600">{{ __('portal.router_fresh.dashboard_intro') }}</p>
            <ul class="space-y-1 text-sm">
                @foreach ($routerAttention as $entry)
                    @php($result = $entry['result'])
                    <li data-router-outdated="{{ $entry['router']->id }}">
                        <span class="font-medium">{{ __('portal.router_fresh.router', ['router' => $entry['router']->name, 'site' => $entry['site']->name]) }}</span>:
                        @php($parts = array_filter([
                            $result['pages'] !== [] ? __('portal.router_fresh.pages', ['pages' => \App\Nas\RouterNotifier::files($result['pages'])]) : null,
                            $result['blocks'] !== [] ? __('portal.router_fresh.blocks', ['blocks' => collect($result['blocks'])->map(fn ($b) => __('portal.router_api.blocks.'.$b))->implode(', ')]) : null,
                            $result['unreachable'] ? __('portal.router_fresh.unreachable', ['since' => \App\Support\LocalTime::format($result['unreachableSince']) ?? '?']) : null,
                        ]))
                        {{ implode('; ', $parts) }}
                        <a href="{{ route('admin.sites.setup', $entry['site']) }}#router-update-{{ $entry['router']->id }}" class="underline">{{ __('portal.router_fresh.show') }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.admin>
