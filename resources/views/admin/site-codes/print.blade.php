<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ __('portal.site_codes.notice_title') }} · {{ $site->name }}</title>
    @vite(['resources/css/app.css'])
    {{-- Site code notice (CONCEPT.md §8), always light like the voucher sheet; A4 or A6 portrait. --}}
    @php($a6 = $format === 'a6')
    @php($accent = $branding->values['accent'] ?? null)
    <style>
        .notice { box-sizing: border-box; width: {{ $a6 ? '105mm' : '210mm' }}; min-height: {{ $a6 ? '148mm' : '297mm' }};
            padding: {{ $a6 ? '6mm' : '14mm' }}; margin: 0 auto; background: #fff; color: #0f172a;
            display: flex; flex-direction: column; gap: {{ $a6 ? '2.5mm' : '7mm' }}; }
        .notice .gp-logo { height: {{ $a6 ? '9mm' : '18mm' }}; width: auto; max-width: 60%; {{ $accent ? 'color: '.$accent.';' : '' }} }
        .notice h1 { font-size: {{ $a6 ? '13pt' : '24pt' }}; line-height: 1.15; font-weight: 700; }
        .notice .site { font-size: {{ $a6 ? '9pt' : '16pt' }}; color: #475569; }
        .notice .steps { display: grid; grid-template-columns: 1fr 1fr; gap: {{ $a6 ? '3mm' : '10mm' }}; }
        .notice .step { text-align: center; }
        .notice .step h2 { font-size: {{ $a6 ? '7.5pt' : '14pt' }}; font-weight: 700; margin-bottom: {{ $a6 ? '1mm' : '3mm' }}; line-height: 1.2; }
        .notice .qr { width: {{ $a6 ? '36mm' : '72mm' }}; height: {{ $a6 ? '36mm' : '72mm' }}; margin: 0 auto; }
        .notice .qr svg { width: 100%; height: 100%; }
        .notice .qr-missing { display: flex; align-items: center; justify-content: center; border: 0.4mm dashed #94a3b8; font-size: {{ $a6 ? '6.5pt' : '10pt' }}; padding: 2mm; }
        .notice .step p { font-size: {{ $a6 ? '6.5pt' : '10pt' }}; color: #334155; margin-top: 1mm; word-break: break-word; }
        .notice .code-box { text-align: center; border: 0.5mm solid #0f172a; border-radius: 2mm; padding: {{ $a6 ? '1.5mm' : '4mm' }}; }
        .notice .code { font-size: {{ $a6 ? '30pt' : '64pt' }}; letter-spacing: 0.15em; line-height: 1.05; font-weight: 700; }
        .notice .validity { font-size: {{ $a6 ? '7pt' : '12pt' }}; color: #334155; }
        .notice .langs { display: grid; grid-template-columns: 1fr 1fr; gap: {{ $a6 ? '1.5mm 3mm' : '4mm 8mm' }}; font-size: {{ $a6 ? '6pt' : '10.5pt' }}; line-height: 1.3; color: #1e293b; }
        .notice .langs strong { display: block; font-size: {{ $a6 ? '6.5pt' : '11pt' }}; }
        @media screen { body { background: #e2e8f0; } .notice { box-shadow: 0 1px 4px rgb(0 0 0 / 0.2); } }
        @media print { .no-print { display: none; } @page { size: {{ $a6 ? 'A6' : 'A4' }}; margin: 0; } body { padding: 0 !important; background: #fff; } }
    </style>
</head>
<body class="p-6 text-slate-900">
    <div class="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center gap-2">
        <button onclick="window.print()" class="btn">{{ __('portal.grants.print') }}</button>
        <a href="{{ route('admin.site-codes.print', [$site, 'format' => $a6 ? 'a4' : 'a6']) }}" class="btn-light">{{ $a6 ? __('portal.site_codes.print_a4') : __('portal.site_codes.print_a6') }}</a>
        <a href="{{ route('admin.site-codes.show', $site) }}" class="btn-light">{{ __('portal.back') }}</a>
        @unless ($ssid)<span class="text-sm text-amber-800">{{ __('portal.site_codes.no_ssid') }}</span>@endunless
        @unless ($hasHotspotHost)<span class="text-sm text-amber-800">{{ __('portal.site_codes.no_hotspot_host') }}</span>@endunless
    </div>

    <article class="notice notice-{{ $format }}">
        <header class="flex items-center justify-between gap-4">
            <div>
                <h1>{{ __('portal.guest.title', [], 'de') }} · {{ __('portal.guest.title', [], 'it') }} · {{ __('portal.guest.title', [], 'fr') }} · {{ __('portal.guest.title', [], 'en') }}</h1>
                <div class="site">{{ $site->name }}</div>
            </div>
            <x-branding.logo :branding="$branding" />
        </header>

        <section class="steps">
            <div class="step">
                <h2>@foreach (config('portal.locales') as $l){{ __('portal.site_codes.notice.step_wifi', [], $l) }}@if (! $loop->last)<br>@endif @endforeach</h2>
                @if ($wifiQr)
                    <div class="qr">{!! $wifiQr !!}</div>
                    <p>{{ __('portal.grants.wifi') }}: <strong>{{ $ssid }}</strong></p>
                    @if ($wifiPassword = $site->wifiPassword())<p>{{ __('portal.grants.wifi_password') }}: <strong class="font-mono">{{ $wifiPassword }}</strong></p>@endif
                @else
                    <div class="qr qr-missing">{{ $wifiUnavailable ? __('portal.site_codes.wifi_unavailable') : __('portal.site_codes.no_ssid') }}</div>
                @endif
            </div>
            <div class="step">
                <h2>@foreach (config('portal.locales') as $l){{ __('portal.site_codes.notice.step_sign_in', [], $l) }}@if (! $loop->last)<br>@endif @endforeach</h2>
                <div class="qr">{!! $signInQr !!}</div>
            </div>
        </section>

        <section class="code-box">
            <div class="code font-mono">{{ $siteCode->code }}</div>
            <div class="validity">
                @foreach (config('portal.locales') as $l)
                    {{ __('portal.site_codes.notice.per_device', ['duration' => \Carbon\CarbonInterval::seconds($siteCode->duration_s)->cascade()->locale($l)->forHumans(['parts' => 2, 'join' => true])], $l) }}@if ($siteCode->valid_until) · {{ __('portal.site_codes.notice.valid_until', ['date' => \App\Support\LocalTime::format($siteCode->valid_until, 'd.m.Y H:i')], $l) }}@endif
                    @if (! $loop->last)<br>@endif
                @endforeach
            </div>
        </section>

        <section class="langs">
            @foreach (config('portal.locales') as $l)
                <div lang="{{ $l }}"><strong>{{ __('portal.languages.'.$l) }}</strong>{{ __('portal.site_codes.notice.text', [], $l) }}</div>
            @endforeach
        </section>
    </article>
</body>
</html>
