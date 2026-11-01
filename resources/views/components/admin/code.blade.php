@props(['code', 'title' => null, 'disabled' => false, 'lines' => null, 'placeholders' => null, 'secretsUrl' => null, 'scope' => null])
{{-- Command block with copy button (Alpine is loaded with Livewire on admin pages). Disabled: greyed out
     (router state missing or stale, CONCEPT.md §9), not copyable, marked for assistive technology.
     lines: per-line router state (unmanaged|missing|update|ok|null), coloured with a legend. placeholders (opt-in,
     ['options' => [NAME => suggestions], 'values' => [NAME => prefill]]): one input per <PLACEHOLDER> of
     the code, replaced live in the shown and copied text (browser only, nothing saved). Password
     placeholders are never rendered: "Insert passwords" (off by default) fetches them from secretsUrl
     (POST, admin only); scope picks the router of per-router passwords. --}}
@php
    $secretNames = ['API_PASSWORD', 'WIFI_PASSWORD'];
    $names = [];
    if ($placeholders !== null && preg_match_all('/<([A-Z][A-Z0-9_]*(?:\/[A-Z0-9_]+)?)>/', (string) $code, $found)) {
        $names = array_values(array_diff(array_unique($found[1]), ['PLACEHOLDERS']));
    }
    $inputs = array_values(array_diff($names, $secretNames));
    $secrets = array_values(array_intersect($names, $secretNames));
    $options = (array) ($placeholders['options'] ?? []);
    $values = [];
    foreach ($inputs as $name) {
        $values[$name] = (string) (($placeholders['values'] ?? [])[$name] ?? '');
    }
    $uid = 'gp-code-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));
    $codeLines = explode("\n", (string) $code);
    $legend = is_array($lines) && array_filter($lines) !== [];
    $statusLabel = fn ($s) => __('portal.setup.script.line_status.'.$s);
    $html = implode("\n", array_map(function ($line, $i) use ($lines, $legend, $statusLabel) {
        $st = $legend ? ($lines[$i] ?? null) : null;

        return '<span data-line class="gp-line'.($st ? ' gp-line-'.$st.'" data-line-status="'.$st.'" title="'.e($statusLabel($st)) : '').'">'.e($line).'</span>';
    }, $codeLines, array_keys($codeLines)));
    $cfg = ['names' => $names, 'secret' => $secrets, 'values' => (object) $values, 'url' => $secrets !== [] ? $secretsUrl : null,
        'scope' => $scope !== null ? (string) $scope : null, 'csrf' => $secrets !== [] && $secretsUrl ? csrf_token() : null,
        'labels' => ['no_passwords' => __('portal.setup.script.no_passwords'), 'unfilled' => __('portal.setup.script.unfilled')]];
@endphp
@once
<script>
    // Script blocks: placeholder substitution, optional password insertion, copy (see x-admin.code)
    window.gpScript = function (cfg) {
        var re = /<([A-Z][A-Z0-9_]*(?:\/[A-Z0-9_]+)?)>/g;
        return {
            copied: false, insert: false, loading: false, failed: false, secrets: null, values: cfg.values, src: [],
            init() {
                this.src = Array.prototype.map.call(this.$refs.code.querySelectorAll('[data-line]'), function (el) { return [el, el.textContent]; });
                this.render();
            },
            filled(n) { var v = this.values[n]; return typeof v === 'string' && v.trim() !== '' ? v.trim() : null; },
            secret(n) {
                if (!this.insert || !this.secrets) return null;
                var v = this.secrets[n];
                if (v && typeof v === 'object') v = cfg.scope !== null ? v[cfg.scope] : null;
                return typeof v === 'string' && v !== '' ? v : null;
            },
            render() {
                var self = this;
                this.src.forEach(function (pair) {
                    pair[0].textContent = pair[1].replace(re, function (m, n) {
                        if (cfg.names.indexOf(n) < 0) return m;
                        var v = cfg.secret.indexOf(n) >= 0 ? self.secret(n) : self.filled(n);
                        return v === null ? m : v;
                    });
                });
            },
            get hint() {
                var self = this, out = [];
                if (cfg.secret.some(function (n) { return self.secret(n) === null; })) out.push(cfg.labels.no_passwords);
                var open = cfg.names.filter(function (n) { return cfg.secret.indexOf(n) < 0 && self.filled(n) === null; }).length;
                if (open) out.push(cfg.labels.unfilled.replace(':count', open));
                return out.join(' · ');
            },
            async toggle(on) {
                this.insert = on; this.failed = false;
                if (on && !this.secrets && cfg.url) {
                    this.loading = true;
                    try {
                        var r = await fetch(cfg.url, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
                            headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        this.secrets = await r.json();
                    } catch (e) { this.failed = true; this.insert = false; }
                    this.loading = false;
                }
                if (!this.insert) this.secrets = null;
                this.render();
            },
            copy() {
                var self = this, code = this.$refs.code;
                var done = function () { self.copied = true; setTimeout(function () { self.copied = false; }, 1500); };
                var fallback = function () { var r = document.createRange(); r.selectNodeContents(code);
                    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('copy'); done(); };
                navigator.clipboard ? navigator.clipboard.writeText(code.textContent).then(done, fallback) : fallback();
            },
        };
    };
</script>
@endonce
<div x-data="gpScript(@js($cfg))" @class(['mb-4', 'opacity-50 select-none' => $disabled]) @if ($disabled) aria-disabled="true" data-cli-disabled @endif>
    <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
        <span class="text-sm font-medium">{{ $title }}</span>
        <span class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-amber-800" x-show="hint" x-text="hint" style="display: none" role="status" data-copy-hint></span>
            <button type="button" class="btn-light" @disabled($disabled) @if ($disabled) aria-disabled="true" title="{{ __('portal.router_api.cli_disabled_short') }}" @endif
                x-on:click="copy()" x-text="copied ? @js(__('portal.setup.copied')) : @js(__('portal.setup.copy'))">{{ __('portal.setup.copy') }}</button>
        </span>
    </div>
    @if ($names !== [])
        <div class="mb-2 rounded border border-slate-300 p-2" data-placeholders>
            <p class="mb-1 text-xs text-slate-600">{{ __('portal.setup.script.placeholders') }}: {{ __('portal.setup.script.placeholders_help') }}</p>
            @if ($inputs !== [])
                <div class="grid gap-2 md:grid-cols-3">
                    @foreach ($inputs as $name)
                        <label class="label text-xs"><code>&lt;{{ $name }}&gt;</code>
                            <input class="field py-1 text-sm" autocomplete="off" value="{{ $values[$name] }}" placeholder="&lt;{{ $name }}&gt;" data-placeholder="{{ $name }}"
                                @if (! empty($options[$name])) list="{{ $uid }}-{{ \Illuminate\Support\Str::lower($name) }}" @endif
                                x-on:input="values[@js($name)] = $event.target.value; render()">
                            @if (! empty($options[$name]))
                                <datalist id="{{ $uid }}-{{ \Illuminate\Support\Str::lower($name) }}">
                                    @foreach ($options[$name] as $option)<option value="{{ $option }}"></option>@endforeach
                                </datalist>
                            @endif
                        </label>
                    @endforeach
                </div>
            @endif
            @if ($secrets !== [])
                <div class="mt-2 text-xs" data-secret-placeholders="{{ implode(',', $secrets) }}">
                    @if ($cfg['url'])
                        <label class="font-medium"><input type="checkbox" :checked="insert" :disabled="loading" x-on:change="toggle($event.target.checked)" data-insert-passwords>
                            {{ __('portal.setup.script.insert_passwords') }}</label>
                        <span class="text-slate-500">{{ __('portal.setup.script.insert_passwords_help') }}</span>
                        <span class="text-red-700" x-show="failed" style="display: none">{{ __('portal.setup.script.passwords_failed') }}</span>
                    @endif
                </div>
            @endif
        </div>
    @endif
    @if ($legend)
        <p class="mb-1 flex flex-wrap items-center gap-2 text-xs" data-line-legend>
            <span>{{ __('portal.setup.script.legend') }}</span>
            {{-- only the states that occur in this block --}}
            @foreach (['unmanaged' => 'gp-badge-unmanaged', 'missing' => 'gp-badge-error', 'update' => 'gp-badge-warn', 'ok' => 'gp-badge-ok'] as $state => $badge)
                @if (in_array($state, $lines, true))
                    <span class="gp-badge {{ $badge }}">{{ $statusLabel($state) }}</span>
                @endif
            @endforeach
        </p>
    @endif
    <pre x-ref="code" @class(['overflow-x-auto rounded p-3 text-xs', 'bg-slate-900 text-slate-100' => ! $disabled, 'bg-slate-500 text-slate-200' => $disabled])>{!! $html !!}</pre>
</div>
