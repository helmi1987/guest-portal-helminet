<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ __('portal.toolbox.wifi_qr.title') }} · {{ $ssid }}</title>
    @vite(['resources/css/app.css'])
    {{-- Toolbox Wi-Fi QR sheet, always light like the site code notice; A4 or A6 portrait. Nothing stored (no-store). --}}
    @php($a6 = $format === 'a6')
    @php($accent = $branding->values['accent'] ?? null)
    <style>
        .notice { box-sizing: border-box; width: {{ $a6 ? '105mm' : '210mm' }}; min-height: {{ $a6 ? '148mm' : '297mm' }};
            padding: {{ $a6 ? '6mm' : '14mm' }}; margin: 0 auto; background: #fff; color: #0f172a;
            display: flex; flex-direction: column; align-items: center; gap: {{ $a6 ? '4mm' : '10mm' }}; text-align: center; }
        .notice .gp-logo { height: {{ $a6 ? '9mm' : '18mm' }}; width: auto; max-width: 60%; {{ $accent ? 'color: '.$accent.';' : '' }} }
        .notice h1 { font-size: {{ $a6 ? '13pt' : '24pt' }}; line-height: 1.15; font-weight: 700; }
        .notice .ssid { font-size: {{ $a6 ? '16pt' : '32pt' }}; font-weight: 700; word-break: break-word; }
        .notice .qr { width: {{ $a6 ? '60mm' : '120mm' }}; height: {{ $a6 ? '60mm' : '120mm' }}; }
        .notice .qr svg { width: 100%; height: 100%; }
        .notice .password { font-size: {{ $a6 ? '11pt' : '20pt' }}; word-break: break-all; }
        .notice .hint { font-size: {{ $a6 ? '7pt' : '12pt' }}; color: #334155; }
        @media screen { body { background: #e2e8f0; } .notice { box-shadow: 0 1px 4px rgb(0 0 0 / 0.2); } }
        @media print { .no-print { display: none; } @page { size: {{ $a6 ? 'A6' : 'A4' }}; margin: 0; } body { padding: 0 !important; background: #fff; } }
    </style>
</head>
<body class="p-6 text-slate-900">
    <div class="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center gap-2">
        <button onclick="window.print()" class="btn">{{ __('portal.grants.print') }}</button>
        <button type="button" onclick="window.close()" class="btn-light">{{ __('portal.toolbox.close_tab') }}</button>
    </div>

    <article class="notice notice-{{ $format }}">
        <x-branding.logo :branding="$branding" />
        <h1>{{ __('portal.grants.wifi') }}</h1>
        <div class="ssid">{{ $ssid }}</div>
        <div class="qr">{!! $qr !!}</div>
        @if ($showPassword)
            <div class="password">{{ __('portal.grants.wifi_password') }}: <strong class="font-mono">{{ $password }}</strong></div>
        @elseif ($password === null)
            <div class="hint">{{ __('portal.toolbox.wifi_qr.open_network') }}</div>
        @endif
        @if ($hidden)
            <div class="hint">{{ __('portal.toolbox.wifi_qr.hidden_hint') }}</div>
        @endif
        <div class="hint">{{ __('portal.toolbox.wifi_qr.scan_hint') }}</div>
    </article>
</body>
</html>
