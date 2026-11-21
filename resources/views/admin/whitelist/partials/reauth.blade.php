{{-- Result of the re-authentication by the portal (decision 2026-11-21, v0.33.0): routers asked through their API
     access (job queued, result in the audit log) and routers without it, with the RouterOS command to run there. --}}
@if (is_array($reauth = session('reauth')))
    <div class="card mb-6" data-reauth-result>
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.reauth.title') }}</h2>
        @if ($reauth['queued'] !== [])
            <p class="mb-2 text-sm text-green-800" data-reauth-queued>{{ __('portal.reauth.queued', ['routers' => implode(', ', $reauth['queued'])]) }}</p>
        @endif
        @if ($reauth['manual'] !== [])
            <p class="mb-2 text-sm text-slate-700" data-reauth-manual>{{ __('portal.reauth.manual', ['routers' => implode(', ', $reauth['manual'])]) }}</p>
            <x-admin.code :title="__('portal.reauth.command')" :code="$reauth['command']" />
        @endif
        @if ($reauth['queued'] === [] && $reauth['manual'] === [])
            <p class="text-sm text-slate-600">{{ __('portal.reauth.no_routers') }}</p>
        @endif
    </div>
@endif
