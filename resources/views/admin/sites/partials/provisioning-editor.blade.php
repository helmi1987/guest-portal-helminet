{{-- Provisioning editor of one router (CAPsMAN v1/v2, decision 2026-11-02): rules and configurations from
     the stored router state; the edits stay in the browser and are sent only to generate the script
     (POST JSON, admin only, nothing stored). Reusable (setup wizard). Needs: $site, $router, $packages
     (ProvisioningEditor::provisioningRules()). --}}
@once
<script>
    window.gpProvisioning = function (cfg) {
        return {
            rules: cfg.rules, added: [], configurations: cfg.configurations, bands: cfg.bands, script: '', errors: [], loading: false, copied: false,
            addSlave(list, name) { if (name && list.indexOf(name) < 0) list.push(name); },
            removeSlave(list, i) { list.splice(i, 1); },
            addRule() { this.added.push({ band: this.bands[0], master: '', slaves: [] }); },
            async generate() {
                this.loading = true; this.errors = []; this.script = '';
                var rules = {};
                this.rules.forEach(function (r) { if (!r.own) rules[r.id] = { master: r.master, slaves: r.slaves }; });
                try {
                    var r = await fetch(cfg.url, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ mode: cfg.mode, rules: rules, new: this.added }) });
                    var data = await r.json().catch(function () { return {}; });
                    if (r.ok) { this.script = data.script || ''; }
                    else { this.errors = data.errors ? Object.values(data.errors).flat() : [cfg.labels.failed + ' (HTTP ' + r.status + ')']; }
                } catch (e) { this.errors = [cfg.labels.failed]; }
                this.loading = false;
            },
            copy() {
                var self = this;
                navigator.clipboard && navigator.clipboard.writeText(this.script).then(function () { self.copied = true; setTimeout(function () { self.copied = false; }, 1500); });
            },
        };
    };
</script>
@endonce
<details class="mb-4 rounded border border-slate-300 p-3" data-provisioning-editor="{{ $router->id }}">
    <summary class="cursor-pointer text-sm font-medium">{{ __('portal.setup.provisioning.title', ['name' => $router->name]) }}</summary>
    <p class="my-2 text-xs text-slate-600">{{ __('portal.setup.provisioning.help') }}</p>
    @foreach ($packages as $mode => $pkg)
        @php($cfg = ['mode' => $mode, 'url' => route('admin.sites.setup.provisioning', [$site, $router]), 'csrf' => csrf_token(),
            'configurations' => $pkg['configurations'], 'bands' => $pkg['bands'], 'rules' => $pkg['rules'],
            'labels' => ['failed' => __('portal.setup.provisioning.failed')]])
        <div class="mb-3" x-data="gpProvisioning(@js($cfg))" data-provisioning-package="{{ $mode }}">
            <p class="mb-1 text-sm font-medium">{{ __('portal.setup.network.modes.'.$mode) }} <code class="text-xs">{{ $pkg['path'] }} provisioning</code></p>
            <div class="overflow-x-auto">
                <table class="data-table text-sm">
                    <thead><tr><th>#</th><th>{{ __('portal.setup.provisioning.band') }}</th><th>{{ __('portal.setup.provisioning.master') }}</th>
                        <th>{{ __('portal.setup.provisioning.slaves') }}</th><th>{{ __('portal.setup.provisioning.match') }}</th></tr></thead>
                    <tbody>
                        <template x-for="rule in rules" :key="rule.id">
                            <tr :data-rule="rule.id">
                                <td><code x-text="rule.id"></code></td>
                                <td x-text="rule.band || @js(__('portal.setup.provisioning.any_band'))"></td>
                                <td>
                                    <template x-if="rule.own"><span x-text="rule.master"></span></template>
                                    <template x-if="!rule.own">
                                        <select class="field py-1" x-model="rule.master" :aria-label="@js(__('portal.setup.provisioning.master'))">
                                            <template x-for="c in configurations" :key="c"><option :value="c" x-text="c" :selected="c === rule.master"></option></template>
                                        </select>
                                    </template>
                                </td>
                                <td>
                                    <span class="flex flex-wrap items-center gap-1">
                                        <template x-for="(s, i) in rule.slaves" :key="s">
                                            <span class="gp-badge gp-badge-neutral"><span x-text="s"></span>
                                                <button type="button" x-show="!rule.own" class="ml-1" x-on:click="removeSlave(rule.slaves, i)" :aria-label="@js(__('portal.setup.provisioning.remove_slave')) + ' ' + s">&times;</button></span>
                                        </template>
                                        <select class="field py-1 text-xs" x-show="!rule.own" x-on:change="addSlave(rule.slaves, $event.target.value); $event.target.value = ''" :aria-label="@js(__('portal.setup.provisioning.add_slave'))">
                                            <option value="">{{ __('portal.setup.provisioning.add_slave') }}</option>
                                            <template x-for="c in configurations" :key="c"><option :value="c" x-text="c"></option></template>
                                        </select>
                                    </span>
                                </td>
                                <td class="text-xs">
                                    <span x-show="rule.radio_mac" x-text="'radio-mac ' + rule.radio_mac"></span>
                                    <span x-show="rule.identity" x-text="'identity ' + rule.identity"></span>
                                    <span x-show="rule.disabled" class="text-amber-800">{{ __('portal.setup.provisioning.disabled') }}</span>
                                    <span x-show="rule.own" class="gp-badge gp-badge-info">{{ __('portal.setup.provisioning.own') }}</span>
                                </td>
                            </tr>
                        </template>
                        <template x-for="(rule, n) in added" :key="n">
                            <tr data-new-rule>
                                <td>{{ __('portal.setup.provisioning.new') }}</td>
                                <td><select class="field py-1" x-model="rule.band" :aria-label="@js(__('portal.setup.provisioning.band'))">
                                    <template x-for="b in bands" :key="b"><option :value="b" x-text="b"></option></template></select></td>
                                <td><select class="field py-1" x-model="rule.master" :aria-label="@js(__('portal.setup.provisioning.master'))">
                                    <option value="">–</option>
                                    <template x-for="c in configurations" :key="c"><option :value="c" x-text="c"></option></template></select></td>
                                <td>
                                    <span class="flex flex-wrap items-center gap-1">
                                        <template x-for="(s, i) in rule.slaves" :key="s">
                                            <span class="gp-badge gp-badge-neutral"><span x-text="s"></span>
                                                <button type="button" class="ml-1" x-on:click="removeSlave(rule.slaves, i)" :aria-label="@js(__('portal.setup.provisioning.remove_slave')) + ' ' + s">&times;</button></span>
                                        </template>
                                        <select class="field py-1 text-xs" x-on:change="addSlave(rule.slaves, $event.target.value); $event.target.value = ''" :aria-label="@js(__('portal.setup.provisioning.add_slave'))">
                                            <option value="">{{ __('portal.setup.provisioning.add_slave') }}</option>
                                            <template x-for="c in configurations" :key="c"><option :value="c" x-text="c"></option></template>
                                        </select>
                                    </span>
                                </td>
                                <td><button type="button" class="btn-light" x-on:click="added.splice(n, 1)">{{ __('portal.setup.provisioning.remove_rule') }}</button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p class="my-2 flex flex-wrap gap-2">
                <button type="button" class="btn-light" x-on:click="addRule()">{{ __('portal.setup.provisioning.add_rule') }}</button>
                <button type="button" class="btn" x-on:click="generate()" :disabled="loading" data-provisioning-generate>{{ __('portal.setup.provisioning.generate') }}</button>
            </p>
            <ul class="text-xs text-red-700" role="alert" x-show="errors.length" style="display: none"><template x-for="e in errors"><li x-text="e"></li></template></ul>
            <div x-show="script" style="display: none" class="mb-2">
                <div class="mb-1 flex justify-end"><button type="button" class="btn-light" x-on:click="copy()" x-text="copied ? @js(__('portal.setup.copied')) : @js(__('portal.setup.copy'))"></button></div>
                <pre class="overflow-x-auto rounded bg-slate-900 p-3 text-xs text-slate-100" x-text="script" data-provisioning-script></pre>
            </div>
        </div>
    @endforeach
    <p class="text-xs text-amber-800">{{ __('portal.setup.provisioning.hint') }}</p>
</details>
