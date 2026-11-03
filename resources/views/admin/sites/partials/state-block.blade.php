{{-- Router state of one block (one router): status counts and the items with their differences and adopt
     commands. Used by the setup page and the wizard. Needs: $block, $items (compareState() block). --}}
@php
    // Solid badges (WCAG AA in both schemes); legacy/differs = will be updated by the next run,
    // unmanaged = name taken by an object without comment (adopt command once, decision 2026-11-01),
    // leftover = own Wi-Fi object of a mode no longer selected (clean-up block, decision 2026-11-02)
    $statusClass = ['present' => 'gp-badge gp-badge-ok', 'foreign' => 'gp-badge gp-badge-ok', 'legacy' => 'gp-badge gp-badge-warn', 'differs' => 'gp-badge gp-badge-warn',
        'missing' => 'gp-badge gp-badge-error', 'unmanaged' => 'gp-badge gp-badge-unmanaged', 'leftover' => 'gp-badge gp-badge-error', 'unknown' => 'gp-badge gp-badge-neutral'];
@endphp
@php($counts = collect($items)->countBy('status'))
<details class="rounded border border-slate-300 p-2 text-sm" data-state-block="{{ $block }}">
    <summary class="cursor-pointer">
        <span class="font-medium">{{ __('portal.router_api.blocks.'.$block) }}</span>
        @foreach (['present', 'foreign', 'legacy', 'differs', 'missing', 'unmanaged', 'leftover', 'unknown'] as $status)
            @if ($counts->get($status))
                <span class="{{ $statusClass[$status] }}">{{ $counts->get($status) }} {{ __('portal.router_api.status.'.$status) }}</span>
            @endif
        @endforeach
    </summary>
    <ul class="mt-1 space-y-1">
        @foreach ($items as $key => $item)
            <li data-state-item="{{ $key }}" data-status="{{ $item['status'] }}">
                <span class="{{ $statusClass[$item['status']] }}">{{ __('portal.router_api.status.'.$item['status']) }}</span>
                <code class="text-xs">{{ $item['path'] }}{{ $item['name'] ? ' '.$item['name'] : '' }}</code> <span class="text-xs text-slate-500">{{ $key }}</span>
                @if ($item['info'] ?? null)
                    <span class="gp-badge gp-badge-neutral" data-state-info="{{ $item['info'] }}">{{ __('portal.router_api.info.'.$item['info']) }}</span>
                @endif
                @if ($item['status'] === 'unmanaged')
                    <div class="pl-4 text-xs" data-adopt="{{ $key }}">@if ($item['adopt'] ?? null)<code>{{ $item['adopt'] }}</code>@else{{ __('portal.router_api.adopt_ambiguous') }}@endif</div>
                @endif
                @foreach ($item['diff'] as $prop => [$want, $actual])
                    <div class="pl-4 text-xs text-amber-800">{{ $prop }}: {{ __('portal.router_api.expected') }} <code>{{ $want }}</code>, {{ __('portal.router_api.actual') }} <code>{{ $actual === '' ? '–' : $actual }}</code></div>
                @endforeach
            </li>
        @endforeach
    </ul>
</details>
