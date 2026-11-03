{{-- Wi-Fi QR code (toolbox): the password is never echoed back (no value, not flashed). --}}
<x-layouts.admin :title="__('portal.toolbox.wifi_qr.title')">
    <h1 class="mb-4 text-2xl font-semibold">{{ __('portal.toolbox.wifi_qr.title') }}</h1>
    <p class="mb-4 text-sm text-slate-600">{{ __('portal.toolbox.wifi_qr.help') }}</p>

    @if ($errors->any())
        <ul class="mb-4 rounded bg-red-100 px-4 py-2 text-sm text-red-800">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif

    {{-- Print sheet in a new tab: this form keeps its values (nothing is stored) --}}
    <form id="wifi-qr-form" method="POST" action="{{ route('admin.toolbox.wifi-qr.print') }}" target="_blank" class="card grid gap-4 md:grid-cols-2">
        @csrf
        <label class="label">{{ __('portal.toolbox.wifi_qr.ssid') }}
            <input name="ssid" value="{{ old('ssid') }}" required maxlength="32" autocomplete="off" class="field"></label>
        <label class="label">{{ __('portal.toolbox.wifi_qr.encryption') }}
            <select name="encryption" class="field">
                <option value="WPA" @selected(old('encryption', 'WPA') === 'WPA')>{{ __('portal.toolbox.wifi_qr.encryption_wpa') }}</option>
                <option value="nopass" @selected(old('encryption') === 'nopass')>{{ __('portal.toolbox.wifi_qr.encryption_nopass') }}</option>
            </select></label>
        <label class="label md:col-span-2">{{ __('portal.toolbox.wifi_qr.password') }}
            <input type="password" name="wifi_password" maxlength="63" autocomplete="new-password" class="field font-mono">
            <span class="text-xs text-slate-500">{{ __('portal.toolbox.wifi_qr.password_help') }}</span></label>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="hidden" value="0">
            <input type="checkbox" name="hidden" value="1" @checked(old('hidden') === '1')> {{ __('portal.toolbox.wifi_qr.hidden') }}</label>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="show_password" value="0">
            <input type="checkbox" name="show_password" value="1" @checked(old('show_password', '1') === '1')> {{ __('portal.toolbox.wifi_qr.show_password') }}</label>
        <label class="label">{{ __('portal.toolbox.wifi_qr.format') }}
            <select name="format" class="field">
                <option value="a4" @selected(old('format', 'a4') === 'a4')>A4</option>
                <option value="a6" @selected(old('format') === 'a6')>A6</option>
            </select></label>
        <p class="text-xs text-slate-500 md:col-span-2">{{ __('portal.toolbox.wifi_qr.not_stored') }}</p>
        <div class="md:col-span-2 flex gap-2">
            <button class="btn">{{ __('portal.toolbox.wifi_qr.submit') }}</button>
            <a href="{{ route('admin.toolbox.index') }}" class="btn-light">{{ __('portal.back') }}</a>
        </div>
    </form>
    {{-- Live preview: debounced POST, stale responses ignored. SVG is server-generated; SSID/password via x-text. --}}
    <div class="card mt-4" x-data="wifiQrPreview(@js(['url' => route('admin.toolbox.wifi-qr.preview'), 'csrf' => csrf_token(), 'form' => 'wifi-qr-form']))">
        <h2 class="mb-2 text-lg font-semibold">{{ __('portal.toolbox.wifi_qr.preview') }}</h2>
        <p x-show="!data" class="text-sm text-slate-500">{{ __('portal.toolbox.wifi_qr.preview_hint') }}</p>
        <div x-show="data" x-cloak class="flex flex-col items-center gap-2 text-center">
            <div class="w-40" x-ref="qr"></div>
            <p class="text-lg font-semibold break-all" x-text="data && data.ssid"></p>
            <p x-show="data && data.password !== null" class="text-sm break-all">
                {{ __('portal.toolbox.wifi_qr.preview_password') }}: <span class="font-mono" x-text="data && data.password"></span></p>
        </div>
    </div>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('wifiQrPreview', (cfg) => ({
                data: null, seq: 0, timer: null,
                init() {
                    const form = document.getElementById(cfg.form);
                    const schedule = () => { clearTimeout(this.timer); this.timer = setTimeout(() => this.update(form), 500); };
                    form.addEventListener('input', schedule);
                    form.addEventListener('change', schedule);
                    schedule();
                },
                async update(form) {
                    const n = ++this.seq;
                    // No request while the SSID is empty: the server would only answer 422.
                    if (!(form.elements.ssid && form.elements.ssid.value.trim())) {
                        this.data = null; this.$refs.qr.innerHTML = '';
                        return;
                    }
                    try {
                        const r = await fetch(cfg.url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', body: new FormData(form),
                            headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                        const j = r.ok ? await r.json() : null;
                        if (n !== this.seq) return;
                        this.data = j;
                        this.$refs.qr.innerHTML = j ? j.svg : '';
                    } catch (e) {
                        if (n === this.seq) { this.data = null; this.$refs.qr.innerHTML = ''; }
                    }
                },
            }));
        });
    </script>
</x-layouts.admin>
