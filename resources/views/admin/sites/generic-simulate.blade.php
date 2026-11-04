{{-- "Simulate redirect" of a generic RADIUS captive-portal site (decision 2026-11-04, v0.28.0): a sample
     redirect after the saved mapping and what the portal reads from it. Nothing is stored. --}}
<x-layouts.admin :title="__('portal.generic.sim.title')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('portal.generic.sim.title') }}: {{ $site->name }}</h1>
    <p class="mb-4 rounded bg-amber-100 px-4 py-2 text-sm text-amber-900">{{ __('portal.generic.experimental') }}</p>
    <section class="card mb-6" data-generic-sim-url>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.generic.sim.url') }}</h2>
        <p class="mb-2 text-sm text-slate-600">{{ __('portal.generic.sim.url_help') }}</p>
        <pre class="overflow-x-auto rounded bg-slate-900 p-4 text-xs text-slate-100">{{ $url }}</pre>
        <a href="{{ $url }}" class="btn-light mt-3 inline-block" target="_blank" rel="noopener">{{ __('portal.generic.sim.open') }}</a>
    </section>
    <section class="card mb-6" data-generic-sim-result>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.generic.sim.result') }}</h2>
        <p @class(['mb-3 rounded px-4 py-2 text-sm', 'bg-green-100 text-green-800' => $complete, 'bg-red-100 text-red-800' => ! $complete])
            data-generic-sim-status="{{ $complete ? 'complete' : ($misconfigured ? 'misconfigured' : 'incomplete') }}">
            {{ $complete ? __('portal.generic.sim.complete') : ($misconfigured ? __('portal.generic.sim.misconfigured') : __('portal.generic.sim.incomplete')) }}</p>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
            <dt>{{ __('portal.generic.sim.mac') }}</dt><dd class="font-mono">{{ $context->mac ?? '–' }}</dd>
            <dt>{{ __('portal.generic.sim.ip') }}</dt><dd class="font-mono">{{ $context->ip ?? '–' }}</dd>
            <dt>{{ __('portal.generic.sim.login_url') }}</dt><dd class="break-all font-mono">{{ $context->loginUrl ?? '–' }}</dd>
            <dt>{{ __('portal.generic.sim.orig_url') }}</dt><dd class="break-all font-mono">{{ $context->origUrl ?? '–' }}</dd>
        </dl>
    </section>
    <section class="card mb-6" data-generic-sim-handoff>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.generic.sim.handoff') }}</h2>
        @if ($handoff)
            <p class="mb-2 font-mono text-sm">{{ $handoff['method'] }} {{ $handoff['url'] }}</p>
            <table class="data-table">
                <tbody>
                    @foreach ($handoff['fields'] as $name => $value)
                        <tr><td class="font-mono">{{ $name }}</td><td class="break-all font-mono">{{ $value }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-sm text-slate-600">{{ __('portal.generic.sim.none') }}</p>
        @endif
    </section>
    <p class="flex gap-2">
        <a href="{{ route('admin.sites.edit', $site) }}#generic" class="btn-light">{{ __('portal.generic.settings_link') }}</a>
        <a href="{{ route('admin.sites.setup', $site) }}" class="btn-light">{{ __('portal.setup.title') }}</a>
    </p>
</x-layouts.admin>
