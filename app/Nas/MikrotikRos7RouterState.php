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
            self::upsert('/user group', "name={$group}", $kGroup, "name={$group}", 'policy='.self::API_POLICY),
            '# Replace <API_PASSWORD> with the password stored for this router in the portal (re-running sets it again)',
            self::upsert('/user', "name={$user}", $kUser, "name={$user}", "group={$group} password=\"<API_PASSWORD>\""),
            '# Optional: allow this user only from the portal instance',
            "# /user set [find where name={$user} && comment=\"{$kUser}\"] address={$radius}/32",
            "# The service must use the router certificate \"{$n}\" (imported by the certificate script, step 4):",
            '# the portal pins its key. Not changed by this script (not an own object); check, and set it if needed:',
            "/ip service print where name={$service}",
            "# /ip service set {$service} certificate={$n} disabled=no port={$port}",
        ]);
    }
}
