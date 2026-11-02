<?php

namespace App\Nas;

use App\Models\NasClient;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

/**
 * CAPsMAN provisioning editor and CAP radio choices for MikrotikRos7Driver (CONCEPT.md §9, decision
 * 2026-11-02). Approved exception to "never change objects of others": the script sets the master and
 * slave configurations of exactly the rules the admin changed in the editor (by .id, only while the router
 * still has the values of the state query) and adds the rules the admin defined (no comment: they belong
 * to the admin, the uninstall never removes them). It never provisions.
 */
trait MikrotikRos7Provisioning
{
    /** Band choices of new rules per package: choice => values of the band matcher. */
    public const PROVISIONING_BANDS = [
        'capsman1' => ['any' => [], '2ghz' => ['b', 'g', 'gn'], '5ghz' => ['a', 'an', 'ac']],
        'capsman2' => ['any' => [], '2ghz' => ['2ghz-g', '2ghz-n', '2ghz-ax'], '5ghz' => ['5ghz-a', '5ghz-n', '5ghz-ac', '5ghz-ax'], '6ghz' => ['6ghz-ax']],
    ];

    /** Most new rules and slave configurations per rule accepted by the editor. */
    public const PROVISIONING_MAX_NEW = 8;

    public const PROVISIONING_MAX_SLAVES = 8;

    public function provisioningRules(NasClient $nas): array
    {
        $menus = is_array($nas->router_state) ? (array) ($nas->router_state['menus'] ?? []) : [];
        $out = [];
        foreach (Site::CAPSMAN_MODES as $mode) {
            $path = self::wifiPath($mode);
            $rulesMenu = $menus["{$path} provisioning"] ?? null;
            $cfgMenu = $menus["{$path} configuration"] ?? null;
            if (! is_array($rulesMenu) || isset($rulesMenu['error']) || ! is_array($cfgMenu) || isset($cfgMenu['error'])) {
                continue;
            }
            $bandProp = $mode === 'capsman1' ? 'hw-supported-modes' : 'supported-bands';
            $configurations = array_values(array_unique(array_filter(array_map(fn ($r) => (string) ($r['name'] ?? ''),
                (array) ($cfgMenu['rows'] ?? [])), fn ($n) => self::validConfigName($n))));
            sort($configurations, SORT_NATURAL);
            $rules = [];
            foreach ((array) ($rulesMenu['rows'] ?? []) as $r) {
                $id = (string) ($r['.id'] ?? '');
                if (! self::validRuleId($id)) {
                    continue;
                }
                $master = (string) ($r['master-configuration'] ?? '');
                $slaves = self::listValue($r['slave-configurations'] ?? '');
                $bands = self::listValue($r[$bandProp] ?? '');
                $rules[] = [
                    'id' => $id,
                    'band' => implode(',', $bands),
                    'master' => $master,
                    'slaves' => $slaves,
                    'radio_mac' => ($mac = Site::normalizeMac((string) ($r['radio-mac'] ?? ''))) !== '00:00:00:00:00:00' ? $mac : null,
                    'identity' => (string) ($r['identity-regexp'] ?? ''),
                    'disabled' => in_array($r['disabled'] ?? null, ['true', 'yes', true], true),
                    'comment' => (string) ($r['comment'] ?? ''),
                    'own' => self::isCapRule($r),
                    // Old values go into quoted strings and comments of the script: only plain names qualify
                    'editable' => self::validConfigName($master)
                        && count(array_filter($slaves, fn ($v) => ! self::validConfigName($v))) === 0
                        && count(array_filter($bands, fn ($v) => preg_match('/^[A-Za-z0-9-]{1,24}\z/', $v) !== 1)) === 0,
                ];
            }
            if ($rules === [] && $configurations === []) {
                continue;
            }
            $out[$mode] = ['path' => $path, 'band_prop' => $bandProp, 'bands' => array_keys(self::PROVISIONING_BANDS[$mode]),
                'configurations' => $configurations, 'rules' => $rules];
        }

        return $out;
    }

    public function provisioningScript(NasClient $nas, array $input): string
    {
        $mode = is_string($input['mode'] ?? null) ? $input['mode'] : '';
        $packages = $this->provisioningRules($nas);
        if (! isset($packages[$mode])) {
            throw ValidationException::withMessages(['mode' => __('portal.setup.provisioning.no_state')]);
        }
        $pkg = $packages[$mode];
        $p = $pkg['path'].' provisioning';
        $configs = $pkg['configurations'];
        $errors = [];
        $names = function (mixed $list, string $field, bool $master) use ($configs, &$errors): array {
            $list = array_values(array_unique(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', (array) $list), fn ($v) => $v !== '')));
            foreach ($list as $name) {
                if (! self::validConfigName($name) || ! in_array($name, $configs, true) || ($master && $name === 'cfg-guest')) {
                    $errors[$field] = __('portal.setup.provisioning.invalid_configuration', ['name' => mb_substr($name, 0, 48)]);
                }
            }
            if (count($list) > self::PROVISIONING_MAX_SLAVES) {
                $errors[$field] = __('portal.setup.provisioning.too_many', ['max' => self::PROVISIONING_MAX_SLAVES]);
            }

            return $list;
        };

        $byId = collect($pkg['rules'])->keyBy('id');
        $changes = [];
        foreach ((array) ($input['rules'] ?? []) as $id => $edit) {
            $id = (string) $id;
            $rule = $byId->get($id);
            if ($rule === null || $rule['own'] || ! is_array($edit)) {
                $errors["rules.{$id}"] = __('portal.setup.provisioning.unknown_rule');

                continue;
            }
            if (! $rule['editable']) {
                $errors["rules.{$id}"] = __('portal.setup.provisioning.not_editable');

                continue;
            }
            [$master] = $names([is_string($edit['master'] ?? null) ? $edit['master'] : ''], "rules.{$id}.master", true) + [null];
            $slaves = $names($edit['slaves'] ?? [], "rules.{$id}.slaves", false);
            if ($master === null) {
                $errors["rules.{$id}.master"] = __('portal.setup.provisioning.master_required');

                continue;
            }
            if ($master !== $rule['master'] || $slaves !== $rule['slaves']) {
                $changes[] = [$rule, $master, $slaves];
            }
        }
        $new = [];
        $added = array_values(array_filter((array) ($input['new'] ?? []), 'is_array'));
        if (count($added) > self::PROVISIONING_MAX_NEW) {
            $errors['new'] = __('portal.setup.provisioning.too_many', ['max' => self::PROVISIONING_MAX_NEW]);
            $added = [];
        }
        foreach ($added as $i => $rule) {
            $band = is_string($rule['band'] ?? null) ? $rule['band'] : '';
            if (! isset(self::PROVISIONING_BANDS[$mode][$band])) {
                $errors["new.{$i}.band"] = __('portal.setup.provisioning.invalid_band');

                continue;
            }
            [$master] = $names([is_string($rule['master'] ?? null) ? $rule['master'] : ''], "new.{$i}.master", true) + [null];
            $slaves = $names($rule['slaves'] ?? [], "new.{$i}.slaves", false);
            if ($master === null) {
                $errors["new.{$i}.master"] = __('portal.setup.provisioning.master_required');

                continue;
            }
            $new[] = [$band, $master, $slaves];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $n = self::NAME;
        $v1 = $mode === 'capsman1';
        $out = [
            "# Provisioning rules of {$pkg['path']} as edited in the setup assistant (generated from the router state",
            '# query; nothing is stored in the portal). Changed rules are addressed by id and set only while they',
            '# still have the master and slave configurations of the state query, else skipped with a message.',
            '# New rules are added at the end, only when no rule with the same band and master configuration',
            '# exists, without comment (they are yours: the uninstall never removes them). Move them if a rule',
            '# before them matches the same radios (first match wins).',
        ];
        foreach ($changes as [$rule, $master, $slaves]) {
            $id = $rule['id'];
            $old = ','.implode(',', $rule['slaves']);
            $slaveSet = $slaves !== [] ? 'slave-configurations='.implode(',', $slaves)
                : ($v1 ? 'slave-configurations=""' : '');
            $unset = $slaves === [] && ! $v1 ? "\n        {$p} unset {$id} slave-configurations" : '';
            $out[] = self::oneLine("# Rule {$id}".($rule['band'] !== '' ? " ({$rule['band']})" : '').": master {$rule['master']} -> {$master}, slaves "
                .(implode(',', $rule['slaves']) ?: '-').' -> '.(implode(',', $slaves) ?: '-'));
            $out[] = ":if ([:len [{$p} find where .id={$id}]] = 0) do={ :put \"{$n}: provisioning rule {$id} not found, skipped\" } else={\n"
                ."    :local gpList \"\"\n"
                ."    :foreach gpSlave in=[{$p} get {$id} slave-configurations] do={ :if ([:len \$gpSlave] > 0) do={ :set gpList (\$gpList . \",\" . \$gpSlave) } }\n"
                ."    :if ([:tostr [{$p} get {$id} master-configuration]] = \"{$rule['master']}\" && \$gpList = \"".($old === ',' ? '' : $old)."\") do={\n"
                ."        {$p} set {$id} master-configuration={$master}".($slaveSet !== '' ? ' '.$slaveSet : '').$unset."\n"
                ."    } else={ :put \"{$n}: provisioning rule {$id} was changed on the router after the state query, skipped\" }\n"
                .'}';
        }
        foreach ($new as [$band, $master, $slaves]) {
            $values = self::PROVISIONING_BANDS[$mode][$band];
            $prop = $pkg['band_prop'];
            $want = '{'.implode(';', array_map(fn ($v) => '"'.$v.'"', $values)).'}';
            $out[] = self::oneLine("# New rule: band {$band}, master {$master}, slaves ".(implode(',', $slaves) ?: '-'));
            $out[] = "{\n"
                ."    :local gpFound false\n"
                ."    :local gpDisabled false\n"
                ."    :foreach gpR in=[{$p} find where master-configuration=\"{$master}\"] do={\n"
                ."        :local gpBands [{$p} get \$gpR {$prop}]\n"
                .'        :local gpSame ([:len $gpBands] = '.count($values).")\n"
                .($values !== [] ? "        :foreach gpWant in={$want} do={ :if ([:typeof [:find \$gpBands \$gpWant]] = \"nil\") do={ :set gpSame false } }\n" : '')
                ."        :if (\$gpSame) do={ :if ([:tostr [{$p} get \$gpR disabled]] = \"true\") do={ :set gpDisabled true } else={ :set gpFound true } }\n"
                ."    }\n"
                ."    :if (\$gpFound) do={ :put \"{$n}: a provisioning rule with band {$band} and master {$master} exists, not added\" } else={\n"
                ."    :if (\$gpDisabled) do={ :put \"{$n}: a provisioning rule with band {$band} and master {$master} exists but is disabled, not added (enable or edit it)\" } else={\n"
                ."        {$p} add action=create-dynamic-enabled master-configuration={$master}"
                .($slaves !== [] ? ' slave-configurations='.implode(',', $slaves) : '')
                .($values !== [] ? " {$prop}=".implode(',', $values) : '')."\n"
                ."    } }\n"
                .'}';
        }
        if ($changes === [] && $new === []) {
            $out[] = '# No changes.';
        }
        $out[] = "{$p} print";
        $out[] = '# Rule changes are applied to the CAPs by CAPsMAN. If a CAP did not pick them up (check the print above';
        $out[] = '# and the CAP\'s interfaces), provision that CAP manually; this script never provisions.';

        return implode("\n", $out);
    }

    public function wifiCapOptions(iterable $routers): array
    {
        $out = ['capsman1' => [], 'capsman2' => []];
        foreach ($routers as $nas) {
            $menus = is_array($nas->router_state) ? (array) ($nas->router_state['menus'] ?? []) : [];
            $rows = fn (string $menu) => (array) ($menus[$menu]['rows'] ?? []);
            // Current master configuration per radio: master interface (no master-interface) with that radio-mac
            $configOf = function (string $menu) use ($rows): array {
                $map = [];
                foreach ($rows($menu) as $r) {
                    $mac = Site::normalizeMac((string) ($r['radio-mac'] ?? ''));
                    if ($mac !== null && in_array((string) ($r['master-interface'] ?? ''), ['', 'none'], true)) {
                        $map[$mac] = (string) ($r['configuration'] ?? '');
                    }
                }

                return $map;
            };
            $v1 = $configOf('/caps-man interface');
            foreach ($rows('/caps-man radio') as $r) {
                if ($mac = Site::normalizeMac((string) ($r['radio-mac'] ?? ''))) {
                    $cap = (string) ($r['remote-cap-identity'] ?? '') ?: (string) ($r['remote-cap-name'] ?? '');
                    $out['capsman1'][$mac] = ['mac' => $mac, 'label' => trim($cap.' '.($r['interface'] ?? '')), 'configuration' => $v1[$mac] ?? ''];
                }
            }
            $v2 = $configOf('/interface wifi');
            foreach ($rows('/interface wifi radio') as $r) {
                // Radios of CAPs only (a local radio of the manager has no cap)
                if (($mac = Site::normalizeMac((string) ($r['radio-mac'] ?? ''))) && (string) ($r['cap'] ?? '') !== '') {
                    $out['capsman2'][$mac] = ['mac' => $mac, 'label' => trim(($r['cap'] ?? '').' '.str_replace(';', ',', (string) ($r['bands'] ?? ''))),
                        'configuration' => $v2[$mac] ?? ''];
                }
            }
        }

        return array_map(function (array $list) {
            ksort($list);

            return array_values($list);
        }, $out);
    }

    /** Configuration name in a provisioning rule: RouterOS name characters only. */
    private static function validConfigName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,48}\z/', $name) === 1;
    }

    /** Text for a comment line of the script: no line breaks (a break would start a new command). */
    private static function oneLine(string $text): string
    {
        return str_replace(["\r", "\n"], ' ', $text);
    }

    /** RouterOS item id as read by the state query ("*1A"). */
    private static function validRuleId(string $id): bool
    {
        return preg_match('/^\*[0-9A-F]{1,8}\z/', $id) === 1;
    }
}
