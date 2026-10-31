<?php

namespace App\Nas;

use App\Models\NasClient;
use App\Models\Site;

/**
 * Router state query of the setup assistant for MikrotikRos7Driver (CONCEPT.md §9, decision
 * 2026-10-31): menus to read, comparison of the generated objects with the router by comment key, and
 * inputs derivable from the router. Read-only access commands (group "guest-portal-ro").
 */
trait MikrotikRos7RouterState
{
    /** Identifying property of the "name" in managedObjects() per menu (default: name). */
    private const IDENT = [
        '/interface bridge port' => 'interface', '/ip address' => 'interface', '/ip dhcp-server network' => 'address',
        '/interface list member' => 'list', '/ip firewall address-list' => 'list', '/interface bridge vlan' => 'bridge',
        '/container envs' => 'list', '/container mounts' => 'list',
    ];

    /** Properties read per menu; never secrets (App\Nas\RouterOs\Menus::guard()). ".id" is always added. */
    private const STATE_PROPS = [
        '/system resource' => ['version', 'board-name'],
        '/interface' => ['name', 'type', 'disabled', 'running'],
        '/interface bridge' => ['name', 'vlan-filtering', 'comment'],
        '/interface bridge port' => ['bridge', 'interface', 'comment'],
        '/interface bridge vlan' => ['bridge', 'vlan-ids', 'tagged', 'comment'],
        '/interface vlan' => ['name', 'interface', 'vlan-id', 'comment'],
        '/interface veth' => ['name', 'address', 'gateway', 'comment'],
        '/interface list' => ['name', 'comment'],
        '/interface list member' => ['list', 'interface', 'comment'],
        '/interface wifi' => ['name', 'configuration', 'master-interface', 'comment'],
        '/interface wifi datapath' => ['name', 'bridge', 'vlan-id', 'comment'],
        '/interface wifi security' => ['name', 'authentication-types', 'comment'],
        '/interface wifi configuration' => ['name', 'ssid', 'comment'],
        '/interface wifi provisioning' => ['action', 'master-configuration', 'slave-configurations', 'comment'],
        '/interface wifi capsman' => ['enabled'],
        '/caps-man manager' => ['enabled'],
        '/caps-man datapath' => ['name', 'bridge', 'vlan-id', 'comment'],
        '/caps-man security' => ['name', 'authentication-types', 'comment'],
        '/caps-man configuration' => ['name', 'ssid', 'comment'],
        '/caps-man provisioning' => ['action', 'master-configuration', 'slave-configurations', 'comment'],
        '/ip address' => ['address', 'interface', 'comment'],
        '/ip pool' => ['name', 'ranges', 'comment'],
        '/ip dhcp-server' => ['name', 'interface', 'address-pool', 'comment'],
        '/ip dhcp-server network' => ['address', 'gateway', 'dns-server', 'dhcp-option', 'comment'],
        '/ip dhcp-server option' => ['name', 'code', 'comment'],
        '/ip dns static' => ['name', 'address', 'type', 'cname', 'comment'],
        '/ip hotspot' => ['name', 'interface', 'address-pool', 'profile', 'disabled'],
        '/ip hotspot profile' => ['name', 'html-directory', 'dns-name', 'hotspot-address', 'login-by', 'ssl-certificate', 'default'],
        '/ip hotspot walled-garden' => ['dst-host', 'action', 'comment'],
        '/ip hotspot walled-garden ip' => ['dst-address', 'protocol', 'dst-port', 'action', 'comment'],
        '/ip firewall filter' => ['chain', 'action', 'comment', 'dynamic', 'disabled'],
        '/ip firewall address-list' => ['list', 'address', 'comment'],
        '/ip service' => ['name', 'port', 'disabled', 'certificate'],
        '/radius' => ['address', 'service', 'comment'],
        '/system script' => ['name', 'comment'],
        '/system scheduler' => ['name', 'interval', 'comment'],
        '/container mounts' => ['list', 'src', 'dst', 'comment'],
        '/container envs' => ['list', 'key', 'comment'],
        '/user group' => ['name', 'policy', 'comment'],
        '/user' => ['name', 'group', 'comment'],
    ];

    /** Only these rows are read from large or sensitive menus (OR conditions). */
    private const STATE_WHERE = [
        '/ip firewall address-list' => ['list=PORTAL', 'list=PORTAL_RADIUS', 'list=RFC1918'],
        '/user group' => ['name='.self::API_GROUP],
        '/user' => ['group='.self::API_GROUP],
    ];

    /** Properties holding lists: compared as sets of the positive entries. */
    private const LIST_PROPS = ['policy', 'login-by', 'authentication-types', 'slave-configurations'];

    /** Objects the firewall block adds only when no equal entry exists (an entry of others is used). */
    private const GUARDED = ['guest-portal:fw:list-guest', 'guest-portal:fw:list-member', 'guest-portal:fw:addr-portal',
        'guest-portal:fw:addr-radius', 'guest-portal:fw:addr-rfc1918-10', 'guest-portal:fw:addr-rfc1918-172', 'guest-portal:fw:addr-rfc1918-192'];

    /** Filter rules with action drop (all others accept). */
    private const FW_DROP = ['in-drop-mgmt', 'fwd-drop-lan', 'fwd-drop-new'];

    public function stateMenus(Site $site): array
    {
        $out = [];
        foreach (self::STATE_PROPS as $menu => $props) {
            $out[$menu] = ['props' => $props] + (isset(self::STATE_WHERE[$menu]) ? ['where' => self::STATE_WHERE[$menu]] : []);
        }

        return $out;
    }

    /**
     * Expected property values of the generated objects (comment key => property => value), only values
     * known without placeholders. Includes the identifying property (name, list, interface, ...).
     *
     * @return array<string, array<string, string>>
     */
    public function expectedValues(Site $site): array
    {
        $g = $this->guestValues($site);
        $radius = self::token($site->setting('radius_address'));
        $portalIp = self::token($site->setting('portal_address')) ?: $radius;
        $portalHost = self::token(parse_url($this->portalUrl($site), PHP_URL_HOST));
        $dnsName = self::token(((array) $site->setting('hotspot_hosts', []))[0] ?? null);
        $k = self::key(...);
        $expect = [
            $k('radius', 'server') => ['address' => $radius, 'service' => 'hotspot'],
            $k('walled', 'portal-host') => ['dst-host' => $portalHost, 'action' => 'allow'],
            $k('walled', 'portal-ip') => ['dst-address' => $portalIp, 'protocol' => 'tcp', 'dst-port' => '443', 'action' => 'accept'],
            $k('dns', 'hotspot-name') => ['name' => $dnsName, 'address' => $g['gateway']],
            $k('dns', 'portal-a') => ['address' => $portalIp],
            $k('dns', 'portal-cname') => ['name' => $portalHost, 'type' => 'CNAME', 'cname' => self::NAME.'-v4.internal'],
            $k('net', 'vlan') => ['interface' => $g['bridge'], 'vlan-id' => $g['vlan_id']],
            $k('net', 'bridge-vlan') => ['vlan-ids' => $g['vlan_id']],
            $k('net', 'gateway') => ['address' => $g['gateway'].$g['prefix']],
            $k('net', 'pool') => ['ranges' => $g['pool']],
            $k('net', 'dhcp-server') => ['interface' => $g['interface'], 'address-pool' => $g['pool_name']],
            $k('net', 'dhcp-network') => ['gateway' => $g['gateway'], 'dns-server' => $g['gateway']],
            $k('net', 'option-114') => ['code' => '114'],
            $k('wifi', 'dp-guest') => ['bridge' => $g['bridge'], 'vlan-id' => $g['vlan_id']],
            $k('wifi', 'cfg-guest') => ['ssid' => $g['ssid']],
            $k('fw', 'list-member') => ['interface' => $g['interface']],
            $k('fw', 'addr-portal') => ['address' => $portalIp],
            $k('fw', 'addr-radius') => ['address' => $radius],
            $k('fw', 'addr-rfc1918-10') => ['address' => '10.0.0.0/8'],
            $k('fw', 'addr-rfc1918-172') => ['address' => '172.16.0.0/12'],
            $k('fw', 'addr-rfc1918-192') => ['address' => '192.168.0.0/16'],
            $k('api', 'group') => ['policy' => self::API_POLICY],
            $k('api', 'user') => ['group' => self::API_GROUP],
        ];
        foreach (self::FW_RULES as $rule => $chain) {
            $expect[$k('fw', $rule)] = ['chain' => $chain, 'action' => in_array($rule, self::FW_DROP, true) ? 'drop' : 'accept'];
        }
        foreach ($this->managedObjects($site) as $objects) {
            foreach ($objects as $key => $object) {
                if ($object['name'] !== null) {
                    $expect[$key] = [self::IDENT[$object['path']] ?? 'name' => $object['name']] + ($expect[$key] ?? []);
                }
            }
        }

        return array_map(fn ($props) => array_filter($props, fn ($v) => $v !== '' && ! str_contains($v, '<')), $expect);
    }

    public function compareState(Site $site, array $state): array
    {
        $menus = (array) ($state['menus'] ?? []);
        $expected = $this->expectedValues($site);
        $out = [];
        foreach ($this->managedObjects($site) as $block => $objects) {
            foreach ($objects as $key => $object) {
                $ident = $object['name'] !== null ? (self::IDENT[$object['path']] ?? 'name') : null;
                $out[$block][$key] = ['path' => $object['path'], 'name' => $object['name']]
                    + $this->compareObject($menus[$object['path']] ?? null, $key, $expected[$key] ?? [], $ident);
            }
        }
        // Hotspot server and profile have no comment: identified by name
        $g = $this->guestValues($site);
        $dnsName = self::token(((array) $site->setting('hotspot_hosts', []))[0] ?? null);
        $byName = [
            '/ip hotspot' => ['interface' => $g['interface'], 'address-pool' => $g['pool_name'], 'profile' => self::NAME],
            '/ip hotspot profile' => ['html-directory' => $g['html_directory'], 'dns-name' => $dnsName, 'hotspot-address' => $g['gateway'],
                'login-by' => 'https,mac', 'ssl-certificate' => self::NAME],
        ];
        foreach ($byName as $path => $props) {
            $props = array_filter($props, fn ($v) => $v !== '' && ! str_contains($v, '<'));
            $out['hotspot'][$path === '/ip hotspot' ? 'hotspot:server' : 'hotspot:profile'] = ['path' => $path, 'name' => self::NAME]
                + $this->compareObject($menus[$path] ?? null, null, ['name' => self::NAME] + $props, 'name');
        }

        return $out;
    }

    /**
     * Status of one object: matched by the exact comment key (or, without a key, by name); legacy
     * comments of earlier scripts count as "legacy" (present, replaced on the next run). For the guarded
     * adds (GUARDED: added only when missing) an equal entry of others counts as "foreign" (used as is).
     *
     * @return array{status: string, diff: array<string, array{0: string, 1: string}>}
     */
    private function compareObject(?array $menu, ?string $key, array $expect, ?string $ident): array
    {
        if ($menu === null || isset($menu['error'])) {
            return ['status' => 'unknown', 'diff' => []];
        }
        $rows = (array) ($menu['rows'] ?? []);
        $diff = function (array $row) use ($expect): array {
            $d = [];
            foreach ($expect as $prop => $want) {
                $actual = (string) ($row[$prop] ?? '');
                if (! self::same($prop, $want, $actual)) {
                    $d[$prop] = [$want, $actual];
                }
            }

            return $d;
        };
        $match = $key !== null
            ? array_values(array_filter($rows, fn ($r) => ($r['comment'] ?? null) === $key))
            : array_values(array_filter($rows, fn ($r) => $ident !== null && ($r[$ident] ?? null) === ($expect[$ident] ?? null)));
        if ($match !== []) {
            $d = $diff($match[0]);

            return ['status' => $d === [] ? 'present' : 'differs', 'diff' => $d];
        }
        if ($key === null) {
            return ['status' => 'missing', 'diff' => []];
        }
        $n = self::NAME;
        foreach ($rows as $row) {
            $comment = (string) ($row['comment'] ?? '');
            if ($comment !== $n && ! str_starts_with($comment, $n.': ')) {
                continue;
            }
            $fits = $ident !== null ? ($row[$ident] ?? null) === ($expect[$ident] ?? null) : $diff($row) === [];
            if ($fits) {
                return ['status' => 'legacy', 'diff' => $diff($row)];
            }
        }

        if (in_array($key, self::GUARDED, true) && $expect !== []) {
            foreach ($rows as $row) {
                if ($diff($row) === []) {
                    return ['status' => 'foreign', 'diff' => []];
                }
            }
        }

        return ['status' => 'missing', 'diff' => []];
    }

    private static function same(string $prop, string $want, string $actual): bool
    {
        $norm = function (string $v) use ($prop): string {
            $v = trim($v);
            if (in_array($prop, self::LIST_PROPS, true)) {
                $parts = array_filter(array_map('trim', explode(',', str_replace(';', ',', $v))), fn ($p) => $p !== '' && $p[0] !== '!');
                sort($parts);

                return implode(',', $parts);
            }

            return match ($v) {
                'yes' => 'true', 'no' => 'false', default => $v
            };
        };

        return $norm($want) === $norm($actual);
    }

    public function deriveSetup(Site $site, array $state): array
    {
        $menus = (array) ($state['menus'] ?? []);
        $rows = fn (string $menu) => (array) ($menus[$menu]['rows'] ?? []);
        $own = fn (string $menu, string $key) => collect($rows($menu))->first(fn ($r) => ($r['comment'] ?? null) === $key);
        $saved = (array) $site->setting('setup', []);
        $out = [];
        $set = function (string $field, mixed $value, string $source) use (&$out, $saved) {
            if (($saved[$field] ?? '') === '' && $value !== null && $value !== '' && ! isset($out[$field])) {
                $out[$field] = [$value, $source];
            }
        };

        if ($vlan = $own('/interface vlan', self::key('net', 'vlan'))) {
            $set('interface', $vlan['name'] ?? null, 'own');
            $set('bridge', $vlan['interface'] ?? null, 'own');
            $set('vlan_id', ctype_digit((string) ($vlan['vlan-id'] ?? '')) ? (int) $vlan['vlan-id'] : null, 'own');
        }
        if (($addr = $own('/ip address', self::key('net', 'gateway'))) && str_contains((string) ($addr['address'] ?? ''), '/')) {
            [$ip, $bits] = explode('/', $addr['address'], 2);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ctype_digit($bits) && (int) $bits >= 16 && (int) $bits <= 30) {
                $mask = (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;
                $set('gateway', $ip, 'own');
                $set('subnet', long2ip(ip2long($ip) & $mask).'/'.$bits, 'own');
            }
            $set('interface', $addr['interface'] ?? null, 'own');
        }
        if ($pool = $own('/ip pool', self::key('net', 'pool'))) {
            $set('pool', preg_match('/^[0-9.]+-[0-9.]+$/', (string) ($pool['ranges'] ?? '')) ? $pool['ranges'] : null, 'own');
        }
        $capsCfg = $own('/caps-man configuration', self::key('wifi', 'cfg-guest'));
        $wifiCfg = $own('/interface wifi configuration', self::key('wifi', 'cfg-guest'));
        $set('ssid', ($capsCfg ?? $wifiCfg)['ssid'] ?? null, 'own');
        $enabled = fn (string $menu) => in_array(($rows($menu)[0]['enabled'] ?? ''), ['true', 'yes'], true);
        if ($capsCfg !== null) {
            $set('wifi_mode', 'capsman1', 'own');
        } elseif ($own('/interface wifi', self::key('wifi', 'wifi-guest')) !== null) {
            $set('wifi_mode', 'local', 'own');
        } elseif ($wifiCfg !== null && $enabled('/interface wifi capsman')) {
            $set('wifi_mode', 'capsman2', 'own');
        } elseif ($enabled('/caps-man manager') && ! $enabled('/interface wifi capsman')) {
            $set('wifi_mode', 'capsman1', 'capsman1');
        } elseif ($enabled('/interface wifi capsman') && ! $enabled('/caps-man manager')) {
            $set('wifi_mode', 'capsman2', 'capsman2');
        }
        $profiles = $rows('/ip hotspot profile');
        $profile = collect($profiles)->first(fn ($p) => ($p['name'] ?? null) === self::NAME);
        if ($profile !== null && in_array($profile['html-directory'] ?? null, self::HTML_DIRECTORIES, true)) {
            $set('html_directory', $profile['html-directory'], 'own');
        }
        $default = collect($profiles)->first(fn ($p) => in_array($p['default'] ?? null, ['true', 'yes'], true));
        if ($default !== null && in_array($default['html-directory'] ?? null, self::HTML_DIRECTORIES, true)) {
            $set('html_directory', $default['html-directory'], 'default_profile');
        }
        // Only bridge of the router (without the container bridge of the assistant): assumption, marked in the UI
        $bridges = array_values(array_filter($rows('/interface bridge'), fn ($b) => ($b['comment'] ?? null) !== self::key('container', 'bridge')));
        if (count($bridges) === 1) {
            $set('bridge', $bridges[0]['name'] ?? null, 'only_bridge');
        }

        return $out;
    }

    /**
     * Display state of each line of a generated script (setup assistant, router script page): the
     * worst status of the comment keys on the line ("missing" > "update" > "ok"), the hotspot server
     * and profile by "add name=guest-portal" in their menu, continuation lines (trailing "\") and the
     * lines inside a "{ ... }" group opened by a line with a state inherit it. Comment lines and lines
     * without a known key stay null. Display only: the script itself is not changed.
     *
     * @param  array<string, string>  $statusByKey  comment key (or hotspot:server|profile) => compare status
     * @return list<?string> one entry per line of $script: missing|update|ok|null
     */
    public function lineStatuses(string $script, array $statusByKey): array
    {
        $class = fn (?string $status) => match ($status) {
            'missing' => 'missing', 'differs', 'legacy' => 'update', 'present', 'foreign' => 'ok', default => null,
        };
        $rank = ['ok' => 1, 'update' => 2, 'missing' => 3];
        $worst = function (array $states) use ($rank): ?string {
            $states = array_values(array_filter($states));
            usort($states, fn ($a, $b) => $rank[$b] <=> $rank[$a]);

            return $states[0] ?? null;
        };
        $byName = ['/ip hotspot' => 'hotspot:server', '/ip hotspot profile' => 'hotspot:profile'];
        $verbs = ['add', 'set', 'remove', 'print', 'unset', 'enable', 'disable', 'find', 'export', 'import'];
        $n = preg_quote(self::NAME, '/');
        $out = [];
        $menu = null;
        $depth = 0;
        $group = null;
        $continued = null;
        foreach (explode("\n", $script) as $line) {
            $t = trim($line);
            $status = null;
            if ($t !== '' && $t[0] !== '#') {
                preg_match_all('/"('.$n.':[a-z0-9-]+:[a-z0-9-]+)"/', $line, $m);
                $status = $worst(array_map(fn ($key) => $class($statusByKey[$key] ?? null), array_unique($m[1])));
                // Menu context ("/ip hotspot profile") or command with its menu ("/ip hotspot add ...")
                $rest = $t;
                if ($t[0] === '/') {
                    $words = explode(' ', $t);
                    $path = [];
                    while ($words !== [] && ! in_array($words[0], $verbs, true)) {
                        $path[] = array_shift($words);
                    }
                    $path = '/'.trim(str_replace('/', ' ', implode(' ', $path)));
                    if ($words === []) {
                        $menu = $path;
                    }
                    $rest = implode(' ', $words);
                    $cmdMenu = $path;
                } else {
                    $cmdMenu = $menu;
                }
                if ($status === null && isset($byName[$cmdMenu]) && preg_match('/^add name='.$n.'( |$)/', $rest)) {
                    $status = $class($statusByKey[$byName[$cmdMenu]] ?? null);
                }
                $status ??= $continued ?? ($depth > 0 ? $group : null);
                // Group "{ ... }" outside quoted strings
                $bare = (string) preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '', $line);
                if ($depth === 0) {
                    $group = $status;
                }
                $depth = max(0, $depth + substr_count($bare, '{') - substr_count($bare, '}'));
                if ($depth === 0) {
                    $group = null;
                }
                $continued = str_ends_with($t, '\\') ? $status : null;
            } else {
                $continued = null;
            }
            $out[] = $status;
        }

        return $out;
    }

    /**
     * Known values for the placeholders of the generated scripts: suggestions from the stored router
     * states (names of interfaces, bridges, pools, Wi-Fi configurations, addresses) and prefill values
     * from the site. Which menu fits which placeholder is a mapping by meaning (assumption), offered as
     * suggestions only: the admin picks or types the value.
     *
     * @param  iterable<NasClient>  $routers
     * @return array{options: array<string, list<string>>, values: array<string, string>}
     */
    public function placeholderHints(Site $site, iterable $routers): array
    {
        $names = [];
        foreach ($routers as $nas) {
            $menus = is_array($nas->router_state) ? (array) ($nas->router_state['menus'] ?? []) : [];
            $col = fn (string $menu, string $prop) => array_map(fn ($r) => (string) ($r[$prop] ?? ''), (array) ($menus[$menu]['rows'] ?? []));
            $add = function (string $placeholder, array $values) use (&$names) {
                foreach ($values as $v) {
                    $v = self::token($v);
                    if ($v !== '' && $v !== 'cfg-guest' && $v !== 'wifi-guest') {
                        $names[$placeholder][$v] = true;
                    }
                }
            };
            $interfaces = $col('/interface', 'name');
            $bridges = $col('/interface bridge', 'name');
            $addresses = array_filter($col('/ip address', 'address'), fn ($a) => str_contains($a, '/'));
            $add('GUEST_INTERFACE', $interfaces);
            $add('TRUNK_PORT_TO_CAP', array_diff($interfaces, $bridges));
            $add('IF', array_diff($interfaces, $bridges));
            $add('WIFI_INTERFACE', $col('/interface wifi', 'name'));
            $add('BRIDGE', $bridges);
            $add('CONTAINER_BRIDGE', $bridges);
            $add('GUEST_POOL', $col('/ip pool', 'name'));
            $add('MASTER_CFG', [...$col('/interface wifi configuration', 'name'), ...$col('/caps-man configuration', 'name')]);
            $add('EXISTING_SLAVES', array_map(fn ($v) => implode(',', array_diff(explode(',', $v), ['cfg-guest'])),
                [...$col('/interface wifi provisioning', 'slave-configurations'), ...$col('/caps-man provisioning', 'slave-configurations')]));
            $add('MANAGER_IP', array_map(fn ($a) => strstr($a, '/', true), $addresses));
            $add('LAN', array_map(function ($a) {
                [$ip, $bits] = explode('/', $a, 2);
                $long = ip2long($ip);

                return $long !== false && ctype_digit($bits) && (int) $bits <= 32
                    ? long2ip($long & ((-1 << (32 - (int) $bits)) & 0xFFFFFFFF)) : '';
            }, $addresses));
        }
        $options = array_map(function ($set) {
            $list = array_keys($set);
            sort($list, SORT_NATURAL);

            return $list;
        }, $names);

        $setup = (array) $site->setting('setup', []);
        $values = array_filter([
            'INSTANCE_IP' => self::token($site->setting('portal_address')) ?: self::token($site->setting('radius_address')),
            'PORTAL_HOST' => self::token(parse_url($this->portalUrl($site), PHP_URL_HOST)),
            'HOTSPOT_DNS_NAME' => self::token(((array) $site->setting('hotspot_hosts', []))[0] ?? null),
            'HOTSPOT_GATEWAY_IP' => self::token($setup['gateway'] ?? null),
            'BRIDGE' => self::token($setup['bridge'] ?? null),
            'GUEST_INTERFACE' => self::token($setup['interface'] ?? null),
        ], fn ($v) => $v !== '');

        return ['options' => $options, 'values' => $values];
    }

    /**
     * Stored passwords for the placeholders of the displayed scripts, escaped for a quoted RouterOS
     * string: WIFI_PASSWORD (null when open or not available here) and API_PASSWORD per router id.
     * Only for the admin's explicit "Insert passwords"; never logged or audited.
     *
     * @param  iterable<NasClient>  $routers
     * @return array{WIFI_PASSWORD: ?string, API_PASSWORD: array<string, ?string>}
     */
    public function scriptSecrets(Site $site, iterable $routers): array
    {
        $wifi = $site->wifiPasswordUnavailable() ? '' : (string) preg_replace('/[^\x20-\x7E]/', '', (string) $site->wifiPassword());
        $api = [];
        foreach ($routers as $nas) {
            $api[(string) $nas->id] = $nas->api_password !== null && $nas->api_password !== '' ? self::quoted((string) $nas->api_password) : null;
        }

        return ['WIFI_PASSWORD' => $wifi !== '' ? self::quoted($wifi) : null, 'API_PASSWORD' => $api];
    }

    /**
     * Read-only API access for the state query of one router: group "guest-portal-ro" (policies read,
     * api, rest-api; no write, no sensitive: the router hides secrets) and the user of the router's
     * access settings, both add-or-update by name + comment key. /ip service is not an own object: only
     * printed, with the settings as a hint.
     */
    public function apiAccessCommands(Site $site, NasClient $nas): string
    {
        $group = self::API_GROUP;
        $user = self::token($nas->api_user) ?: '<API_USER>';
        $service = $nas->api_type === 'api-ssl' ? 'api-ssl' : 'www-ssl';
        $port = $nas->apiPort();
        $n = self::NAME;
        $kGroup = self::key('api', 'group');
        $kUser = self::key('api', 'user');
        $radius = self::token($site->setting('radius_address')) ?: '<INSTANCE_IP>';

        return implode("\n", [
            "# Read-only access for the router state query of the portal ({$service}, port {$port}). The group",
            '# has only the policies '.self::API_POLICY.': no write, no "sensitive" (the router hides secrets).',
            "# Re-runnable: adds the group and the user or updates the own ones (comment keys \"{$kGroup}\",",
            "# \"{$kUser}\"); a group or user of others with these names is never changed (the add fails).",
            '# The script aborts before changing anything while <API_PASSWORD> is not replaced (the first operand is',
            '# replaced with the rest, the split second one is not).',
            '# Wrapped in one "{ ... }" unit so a terminal paste (line by line) is aborted as a whole by the :error.',
            '{',
            ':if ("<API_PASSWORD>" = ("<" . "API_PASSWORD>")) do={ :error "replace <API_PASSWORD> first" }',
            self::upsert('/user group', "name={$group}", $kGroup, "name={$group}", 'policy='.self::API_POLICY),
            '# Replace <API_PASSWORD> with the password stored for this router in the portal (re-running sets it again).',
            self::upsert('/user', "name={$user}", $kUser, "name={$user}", "group={$group} password=\"<API_PASSWORD>\""),
            '}',
            '# Optional: allow this user only from the portal instance',
            "# /user set [find where name={$user} && comment=\"{$kUser}\"] address={$radius}/32",
            "# The service must use the router certificate \"{$n}\" (imported by the certificate script, step 4):",
            '# the portal pins its key. Not changed by this script (not an own object); check, and set it if needed:',
            "/ip service print where name={$service}",
            "# /ip service set {$service} certificate={$n} disabled=no port={$port}",
        ]);
    }
}
