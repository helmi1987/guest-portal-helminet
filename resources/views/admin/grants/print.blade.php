<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ __('portal.grants.vouchers') }}</title>
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none; } @page { margin: 10mm; } }</style>
</head>
<body class="bg-white p-6 text-slate-900">
    <p class="no-print mb-4 flex gap-2">
        <button onclick="window.print()" class="btn">{{ __('portal.grants.print') }}</button>
        <a href="{{ route('admin.grants.index') }}" class="btn-light">{{ __('portal.back') }}</a>
    </p>
    <div class="grid grid-cols-2 gap-4">
        @foreach ($grants as $grant)
            <div class="break-inside-avoid rounded border-2 border-dashed border-slate-400 p-4">
                <div class="text-sm font-semibold">{{ __('portal.guest.title') }}</div>
                @if ($grant->label)<div class="text-xs text-slate-600">{{ $grant->label }}</div>@endif
                <div class="my-2 font-mono text-4xl tracking-widest">{{ $grant->code }}</div>
                <div class="text-sm">{{ __('portal.grants.duration') }}: {{ \App\Support\Duration::format($grant->duration_s) }}</div>
                @if ($grant->expires_at)<div class="text-sm">{{ __('portal.grants.expires_at') }}: {{ $grant->expires_at->format('Y-m-d H:i') }}</div>
                @elseif ($grant->redeem_until)<div class="text-sm">{{ __('portal.grants.redeem_until') }}: {{ $grant->redeem_until->format('Y-m-d H:i') }}</div>@endif
                <div class="mt-2 text-xs text-slate-600">{{ __('portal.grants.voucher_hint') }}</div>
            </div>
        @endforeach
    </div>
</body>
</html>
