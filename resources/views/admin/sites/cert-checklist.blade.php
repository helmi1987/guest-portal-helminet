@php
    // Certificate / login checklist (setup assistant and site page): until the router certificate is
    // imported the hotspot profile has only login-by=mac, so code and SMS logins cannot work.
    $routersOf = $site->nasClients;
    $portalCert = \App\Tls\CertificateInfo::fromFile(\App\Tls\CertificateSources::paths('portal')['cert']);
    $routerCert = \App\Tls\CertificateInfo::fromFile(\App\Tls\CertificateSources::paths('router')['cert']);
    $checks = [
        'portal_cert' => $portalCert !== null,
        'router_cert' => $routerCert !== null,
        'router_script' => $routersOf->isNotEmpty() && $routersOf->every(fn ($r) => $r->cert_fetched_at !== null),
        'login' => $routersOf->isNotEmpty() && $routersOf->every(fn ($r) => $r->login_page_fetched_at !== null),
        'verify' => false,
    ];
@endphp
<section id="cert-checklist" class="card mb-6 border-2 border-amber-300">
    <h2 class="mb-2 text-lg font-semibold">{{ __('portal.setup.checklist.title') }}</h2>
    <p class="mb-3 text-sm font-medium text-amber-800">{{ __('portal.setup.checklist.warning') }}</p>
    <ol class="mb-3 list-decimal space-y-1 pl-6 text-sm">
        @foreach ($checks as $key => $ok)
            <li>{{ __('portal.setup.checklist.steps.'.$key) }}
                @if ($key !== 'verify')
                    <x-admin.step-status :ok="$ok" :label="$ok ? __('portal.setup.done') : __('portal.setup.open')" />
                @endif
                @if (in_array($key, ['portal_cert', 'router_cert'], true))
                    <a href="{{ route('admin.certificates.index') }}" class="underline">{{ __('portal.certificates.title') }}</a>
                @endif
            </li>
        @endforeach
    </ol>
    <x-admin.code :title="__('portal.setup.blocks.verify')" :code="$verify" :placeholders="$placeholders ?? ['options' => [], 'values' => []]" />
</section>
