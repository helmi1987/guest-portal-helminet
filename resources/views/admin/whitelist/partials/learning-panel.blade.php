{{-- Self-refreshing part of the learning window panel (decision 2026-11-21, v0.33.2): open windows and recorded devices.
     Rendered by the whitelist page and by admin.learning.panel; data-learning-poll is only present while a window is open. --}}
<div data-learning-panel @if ($windows->isNotEmpty()) data-learning-poll="{{ route('admin.learning.panel') }}" @endif>
        @foreach ($windows as $window)
            <div class="mb-2 flex flex-wrap items-center gap-3 rounded border border-amber-300 bg-amber-50 p-2 text-sm" data-learning-window="{{ $window->site_id }}">
                <span class="font-medium">{{ $window->site?->name }}</span>
                @php($left = $window->remainingSeconds())
                <span data-learning-remaining="{{ $left }}">{!! __('portal.learning.remaining', ['minutes' => '<span data-cd-m>'.intdiv($left, 60).'</span>', 'seconds' => '<span data-cd-s>'.sprintf('%02d', $left % 60).'</span>', 'until' => e(\App\Support\LocalTime::format($window->expires_at, 'H:i'))]) !!}</span>
                <span class="text-xs text-slate-500">{{ $window->opener?->name ?? '–' }}</span>
                <form method="POST" action="{{ route('admin.learning.close', $window) }}">@csrf
                    <button class="btn-light text-xs">{{ __('portal.learning.close') }}</button></form>
            </div>
        @endforeach
        @if ($attempts->isNotEmpty())
            <div class="mt-4 overflow-x-auto">
                <h3 class="mb-2 font-semibold">{{ __('portal.learning.attempts') }}</h3>
                <table class="data-table">
                    <thead><tr><th>MAC</th><th>{{ __('portal.guests.site') }}</th><th>{{ __('portal.learning.router') }}</th>
                        <th>{{ __('portal.learning.first_seen') }}</th><th>{{ __('portal.learning.last_seen') }}</th><th>{{ __('portal.learning.count') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($attempts as $attempt)
                            <tr data-learning-attempt="{{ $attempt->mac }}">
                                <td class="font-mono">{{ $attempt->mac }}</td>
                                <td class="text-xs">{{ $attempt->site?->name }}@if ($attempt->window?->isOpen()) <span class="gp-badge gp-badge-info">{{ __('portal.learning.window_open') }}</span>@endif</td>
                                <td class="text-xs">{{ $attempt->nasClient?->name ?? '–' }}</td>
                                <td class="text-xs">{{ \App\Support\LocalTime::format($attempt->first_seen_at, 'Y-m-d H:i:s') }}</td>
                                <td class="text-xs">{{ \App\Support\LocalTime::format($attempt->last_seen_at, 'Y-m-d H:i:s') }}</td>
                                <td class="text-xs">{{ $attempt->count }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.learning.take-over', $attempt) }}" class="flex flex-wrap items-center gap-1">@csrf
                                        <input name="comment" required maxlength="255" placeholder="{{ __('portal.whitelist.comment') }}" class="field text-xs" aria-label="{{ __('portal.whitelist.comment') }}">
                                        <button class="btn-light text-xs">{{ __('portal.learning.take_over') }}</button></form>
                                    <form method="POST" action="{{ route('admin.learning.discard', $attempt) }}" class="mt-1">@csrf
                                        <button class="btn-light text-xs">{{ __('portal.learning.discard') }}</button></form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
</div>
