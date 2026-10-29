<x-layouts.admin :title="__('portal.certificates.title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.certificates.title') }}</h1>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.certificates.help') }}</p>

    @foreach ($targets as $target => $t)
        @php($info = $t['info'])
        @php($source = $t['source'])
        @php($status = $t['status'])
        @php($own = old('target') === $target)
        <section class="card mb-6 grid gap-4">
            <h2 class="text-lg font-semibold">{{ __('portal.certificates.targets.'.$target) }}</h2>
            <p class="text-xs text-slate-500"><code>{{ $t['paths']['cert'] }}</code> · <code>{{ $t['paths']['key'] }}</code></p>

            @if ($info)
                <dl class="grid gap-x-4 gap-y-1 text-sm md:grid-cols-[12rem_1fr]">
                    <dt class="text-slate-500">{{ __('portal.certificates.subject') }}</dt><dd>{{ $info->subject }}</dd>
                    <dt class="text-slate-500">{{ __('portal.certificates.names') }}</dt><dd>{{ implode(', ', $info->names) ?: '–' }}</dd>
                    <dt class="text-slate-500">{{ __('portal.certificates.issuer') }}</dt><dd>{{ $info->issuer }}</dd>
                    <dt class="text-slate-500">{{ __('portal.certificates.valid_to') }}</dt>
                    <dd @class(['text-red-700 font-semibold' => $info->expired() || $info->validTo < time() + 14 * 86400])>
                        {{ \Illuminate\Support\Carbon::createFromTimestamp($info->validTo)->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                        @if ($info->expired()) ({{ __('portal.certificates.expired') }}) @endif</dd>
                    <dt class="text-slate-500">{{ __('portal.certificates.fingerprint') }}</dt><dd class="break-all font-mono text-xs">{{ $info->fingerprint }}</dd>
                </dl>
                @if ($target === 'portal' && $portalHost && ! $info->covers($portalHost))
                    <p class="text-sm text-red-700">{{ __('portal.certificates.host_mismatch', ['host' => $portalHost]) }}</p>
                @endif
            @else
                <p class="text-sm text-slate-500">{{ __('portal.certificates.no_file_'.$target) }}</p>
            @endif

            @if ($status)
                <p @class(['text-sm', 'text-red-700' => ($status['result'] ?? null) === 'failed'])>
                    {{ __('portal.certificates.last_pull', ['time' => \Illuminate\Support\Carbon::parse($status['checked_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i')]) }}
                    {{ __('portal.certificates.results.'.($status['result'] ?? 'failed')) }}@if (! empty($status['error'])): {{ $status['error'] }}@endif
                </p>
                @if (! empty($status['warning']))
                    <p class="text-sm text-amber-700">{{ __('portal.certificates.warning', ['warning' => $status['warning']]) }}</p>
                @endif
            @endif

            <form method="POST" action="{{ route('admin.certificates.update', $target) }}" class="grid gap-4 md:grid-cols-2">
                @csrf @method('PUT') <input type="hidden" name="target" value="{{ $target }}">
                <label class="label">{{ __('portal.certificates.type') }}
                    <select name="type" class="field">
                        @foreach (\App\Tls\CertificateSources::TYPES as $type)
                            <option value="{{ $type }}" @selected(($own ? old('type') : $source['type']) === $type)>{{ __('portal.certificates.types.'.$type) }}</option>
                        @endforeach
                    </select></label>
                <label class="label">{{ __('portal.certificates.url') }}
                    <input name="url" value="{{ ($own ? old('url') : \App\Tls\CertificateSources::maskUrl($source['url'])) }}" placeholder="https://certs.example.com" class="field">
                    <span class="text-xs text-slate-500">{{ __('portal.certificates.url_help') }}</span></label>
                <label class="label">{{ __('portal.certificates.name') }}
                    <input name="name" value="{{ ($own ? old('name') : $source['name']) }}" class="field">
                    <span class="text-xs text-slate-500">{{ __('portal.certificates.name_help') }}</span></label>
                <label class="label">{{ __('portal.certificates.api_key') }}
                    <input name="api_key" type="password" autocomplete="off" value=""
                           placeholder="{{ $source['has_api_key'] ? __('portal.channels.secret_keep') : '' }}" class="field">
                    <span class="text-xs text-slate-500">{{ __('portal.certificates.api_key_help') }}</span>
                    @if ($source['has_api_key'])
                        <span class="flex items-center gap-2 text-xs"><input type="hidden" name="remove_api_key" value="0">
                            <input type="checkbox" name="remove_api_key" value="1"> {{ __('portal.certificates.remove_api_key') }}</span>
                    @endif
                </label>
                <label class="label md:col-span-2">{{ __('portal.certificates.ca_pem') }}
                    <textarea name="ca_pem" rows="3" class="field font-mono text-xs" placeholder="-----BEGIN CERTIFICATE-----">{{ ($own ? old('ca_pem') : $source['ca_pem']) }}</textarea>
                    <span class="text-xs text-slate-500">{{ __('portal.certificates.ca_pem_help') }}</span></label>
                <div class="md:col-span-2"><button class="btn">{{ __('portal.save') }}</button></div>
            </form>
            @if ($source['type'] !== 'none')
                <form method="POST" action="{{ route('admin.certificates.pull', $target) }}">
                    @csrf <button class="btn-light">{{ __('portal.certificates.pull') }}</button></form>
            @endif
        </section>
    @endforeach
</x-layouts.admin>
