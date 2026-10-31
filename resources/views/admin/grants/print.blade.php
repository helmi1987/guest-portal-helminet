<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ __('portal.grants.vouchers') }}</title>
    @vite(['resources/css/app.css'])
    {{-- A4 sheet: 2 x 5 cards per page (CONCEPT.md §9). --}}
    <style>
        .sheet { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4mm; }
        .voucher { box-sizing: border-box; height: 53mm; padding: 4mm; display: flex; gap: 4mm; break-inside: avoid;
            border: 0.4mm dashed #94a3b8; border-radius: 2mm; overflow: hidden; }
        .voucher .qr { width: 32mm; height: 32mm; flex-shrink: 0; }
        .voucher .qr svg { width: 100%; height: 100%; }
        .voucher .code { font-size: 26pt; letter-spacing: 0.15em; line-height: 1.1; }
        @media print { .no-print { display: none; } @page { size: A4; margin: 10mm; } body { padding: 0 !important; } }
    </style>
</head>
<body class="bg-white p-6 text-slate-900">
    <p class="no-print mb-4 flex flex-wrap gap-2">
        <button onclick="window.print()" class="btn">{{ __('portal.grants.print') }}</button>
        @if (auth()->user()->isAdmin() && $grants->isNotEmpty())
            <a href="{{ route('admin.grants.export', ['ids' => $grants->pluck('id')->all()]) }}" class="btn-light">{{ __('portal.grants.export_csv') }}</a>
        @endif
        <a href="{{ route('admin.grants.index') }}" class="btn-light">{{ __('portal.back') }}</a>
        <span class="self-center text-sm text-slate-600">{{ trans_choice('portal.grants.count', $grants->count(), ['count' => $grants->count()]) }}</span>
    </p>
    <div class="sheet">
        @foreach ($grants as $grant)
            @php($network = $qr->networkFor($grant))
            @php($ssid = $network['ssid'] ?? null)
            <div class="voucher">
                @if ($ssid)
                    <div class="qr">{!! $qr->wifiSvg($ssid, 160, $network['password']) !!}</div>
                @endif
                <div class="min-w-0">
                    <div class="text-sm font-semibold">{{ __('portal.guest.title') }}</div>
                    @if ($grant->label)<div class="truncate text-xs text-slate-600">{{ $grant->label }}</div>@endif
                    <div class="code my-1 font-mono">{{ $grant->code }}</div>
                    <div class="text-xs">{{ __('portal.grants.duration') }}: {{ \App\Support\Duration::format($grant->duration_s) }}</div>
                    @if ($grant->expires_at)<div class="text-xs">{{ __('portal.grants.expires_at') }}: {{ \App\Support\LocalTime::format($grant->expires_at, 'Y-m-d H:i') }}</div>
                    @elseif ($grant->redeem_until)<div class="text-xs">{{ __('portal.grants.redeem_until') }}: {{ \App\Support\LocalTime::format($grant->redeem_until, 'Y-m-d H:i') }}</div>@endif
                    @if ($ssid)<div class="text-xs">{{ __('portal.grants.wifi') }}: <span class="font-semibold">{{ $ssid }}</span></div>@endif
                    @if ($network['password'] ?? null)<div class="text-xs">{{ __('portal.grants.wifi_password') }}: <span class="font-mono font-semibold">{{ $network['password'] }}</span></div>@endif
                    <div class="mt-1 text-[10px] leading-tight text-slate-600">{{ $ssid ? __('portal.grants.voucher_hint_qr') : __('portal.grants.voucher_hint') }}</div>
                </div>
            </div>
        @endforeach
    </div>
</body>
</html>
