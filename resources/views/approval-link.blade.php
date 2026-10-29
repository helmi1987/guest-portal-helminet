{{-- Signed approval link page (admin port, no login, no session): GET shows, POST decides. --}}
@php($branding = \App\Branding\BrandingSettings::forAdmin())
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('portal.approvals.title') }}</title>
    @vite(['resources/css/app.css'])
    <x-branding.head :branding="$branding" />
</head>
<body class="{{ $branding->bodyClasses('admin', 'approval-link') }} min-h-screen bg-(--gp-bg) text-(--gp-text) antialiased">
<main class="gp-main mx-auto max-w-md p-4 sm:py-10">
    <header class="gp-header mb-4"><x-branding.logo :branding="$branding" /></header>
    <section class="gp-card card">
        <h1 class="mb-4 text-xl font-semibold">{{ __('portal.approvals.notify.title') }}</h1>
        <dl class="mb-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt class="text-slate-500">{{ __('portal.grants.guest') }}</dt><dd>{{ $approval->guestName() }}</dd>
            <dt class="text-slate-500">{{ __('portal.approvals.site') }}</dt><dd>{{ $approval->site?->name }}</dd>
            <dt class="text-slate-500">{{ __('portal.grants.device') }}</dt><dd class="font-mono">{{ $approval->mac }}</dd>
            <dt class="text-slate-500">{{ __('portal.grants.status') }}</dt><dd>{{ $approval->currentStatus()->label() }}</dd>
        </dl>

        @if ($done !== null)
            <p class="rounded px-4 py-2 text-sm {{ $done ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900' }}" role="status">
                {{ $done ? __('portal.approvals.notify.'.($approval->status->value === 'approved' ? 'approved' : 'denied'),
                    ['by' => $approval->decided_by, 'duration' => \App\Support\Duration::format($approval->duration_s)]) : __('portal.approvals.closed') }}
            </p>
        @elseif ($approval->isPending())
            <form method="POST" action="{{ request()->getRequestUri() }}" class="flex flex-col gap-2">
                <p class="mb-1 text-sm text-slate-600">{{ __('portal.approvals.choose') }}</p>
                @foreach (array_keys($durations) as $key)
                    @continue($choice && $choice !== $key)
                    <button name="decision" value="{{ $key }}" class="{{ $choice === $key ? 'btn' : 'btn-light' }} py-3 text-base">
                        {{ __('portal.approvals.choices.'.$key) }}</button>
                @endforeach
                @if (! $choice || $choice === 'deny')
                    <button name="decision" value="deny" class="{{ $choice === 'deny' ? 'btn-danger' : 'btn-light' }} mt-2 py-3 text-base">
                        {{ __('portal.approvals.choices.deny') }}</button>
                @endif
            </form>
        @else
            <p class="rounded bg-amber-100 px-4 py-2 text-sm text-amber-900">{{ __('portal.approvals.closed') }}</p>
        @endif
    </section>
</main>
</body>
</html>
