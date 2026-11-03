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
        // radio-mac: radio of a master interface (guest SSID per CAP, decision 2026-11-02)
        '/interface wifi' => ['name', 'configuration', 'master-interface', 'radio-mac', 'comment'],
        '/interface wifi radio' => ['radio-mac', 'cap', 'bands'],
        '/interface wifi datapath' => ['name', 'bridge', 'vlan-id', 'comment'],
        '/interface wifi security' => ['name', 'authentication-types', 'comment'],
        '/interface wifi configuration' => ['name', 'ssid', 'datapath', 'security', 'comment'],
        // Provisioning editor (decision 2026-11-02): band matcher, matchers and name formats of every rule
        '/interface wifi provisioning' => ['action', 'master-configuration', 'slave-configurations', 'comment', 'disabled',
            'supported-bands', 'radio-mac', 'identity-regexp', 'common-name-regexp', 'name-format', 'slave-name-format'],
        '/interface wifi capsman' => ['enabled'],
        '/caps-man manager' => ['enabled'],
        '/caps-man datapath' => ['name', 'bridge', 'vlan-id', 'comment'],
        '/caps-man security' => ['name', 'authentication-types', 'comment'],
        '/caps-man configuration' => ['name', 'ssid', 'comment'],
        '/caps-man provisioning' => ['action', 'master-configuration', 'slave-configurations', 'comment', 'disabled',
            'hw-supported-modes', 'radio-mac', 'identity-regexp', 'common-name-regexp', 'name-format', 'name-prefix'],
        '/caps-man radio' => ['radio-mac', 'remote-cap-name', 'remote-cap-identity', 'interface'],
        '/caps-man interface' => ['name', 'master-interface', 'configuration', 'radio-mac'],
        '/ip address' => ['address', 'interface', 'comment'],
        '/ip pool' => ['name', 'ranges', 'comment'],
        '/ip dhcp-server' => ['name', 'interface', 'address-pool', 'comment'],
        '/ip dhcp-server network' => ['address', 'gateway', 'dns-server', 'dhcp-option', 'comment'],
        '/ip dhcp-server option' => ['name', 'code', 'comment'],
        // dynamic: entries RouterOS adds for the hotspot (decision 2026-11-02), never own
        '/ip dns static' => ['name', 'address', 'type', 'cname', 'comment', 'dynamic'],
        '/ip hotspot' => ['name', 'interface', 'address-pool', 'profile', 'disabled'],
        '/ip hotspot profile' => ['name', 'html-directory', 'dns-name', 'hotspot-address', 'login-by', 'ssl-certificate', 'default'],
        '/ip hotspot walled-garden' => ['dst-host', 'action', 'comment', 'dynamic'],
        '/ip hotspot walled-garden ip' => ['dst-address', 'protocol', 'dst-port', 'action', 'comment', 'dynamic'],
        '/ip firewall filter' => ['chain', 'action', 'comment', 'dynamic', 'disabled'],
        '/ip firewall address-list' => ['list', 'address', 'comment'],
        '/ip service' => ['name', 'port', 'disabled', 'certificate'],
        '/radius' => ['address', 'service', 'comment'],
        '/system script' => ['name', 'comment'],
        '/system scheduler' => ['name', 'interval', 'comment'],
        // Container step hint (decision 2026-11-02): device mode allows containers (the menu exists without the package too)
        '/system device-mode' => ['container'],
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

    /** Extra identifying properties of an object without comment (adopt command): the env list holds more entries. */
    private const ADOPT_MATCH = ['guest-portal:container:env-app-url' => ['key' => 'APP_URL']];

    /** Objects the scripts replace also without a comment (certificate script and scheduler by name): legacy, not unmanaged. */
    private const LEGACY_NO_COMMENT = ['guest-portal:cert:script', 'guest-portal:cert:scheduler'];

    /**
     * Menus with dynamic entries of the hotspot (decision 2026-11-02): those rows are never own, legacy,
     * foreign or unmanaged (filtered before the comparison; the adopt command skips them too).
     */
    private const DYNAMIC_MENUS = ['/ip hotspot walled-garden', '/ip hotspot walled-garden ip', '/ip dns static'];

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
        foreach ($g['wifi_masters']['local'] ?? [] as $master) {
            $expect[$k('wifi', self::guestInterface($master))] = ['master-interface' => $master, 'configuration' => 'cfg-guest'];
        }
        // Wi-Fi objects by state id (wifiId()): the /caps-man copies expect the same values
        $wifi = $this->wifiManagedObjects($g);
        foreach ($wifi as $id => $object) {
            $key = $object['key'] ?? $id;
            if ($id !== $key && isset($expect[$key])) {
                $expect[$id] = $expect[$key];
            }
        }
        foreach ($g['wifi_caps'] as $macs) {
            foreach ($macs as $mac) {
                $expect[self::capKey($mac)] = ['radio-mac' => $mac];
            }
        }
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
        $all = (array) ($state['menus'] ?? []);
        $menus = self::ownWifiComments(self::withoutDynamic($all));
        $expected = $this->expectedValues($site);
        $out = [];
        foreach ($this->managedObjects($site) as $block => $objects) {
            foreach ($objects as $id => $object) {
                // State id = comment key, except the /caps-man Wi-Fi objects next to the wifi package (wifiId())
                $key = $object['key'] ?? $id;
                $ident = $object['name'] !== null ? (self::IDENT[$object['path']] ?? 'name') : null;
                $out[$block][$id] = ['path' => $object['path'], 'name' => $object['name']] + ($key !== $id ? ['key' => $key] : [])
                    + $this->compareObject($menus[$object['path']] ?? null, $key, $expected[$id] ?? [], $ident, $object['path']);
            }
        }
        $g = $this->guestValues($site);
        // Own provisioning rule per CAP: present only while it lists cfg-guest
        foreach ($g['wifi_caps'] as $macs) {
            foreach ($macs as $mac) {
                $item = &$out['wifi'][self::capKey($mac)];
                $row = collect((array) ($menus[$item['path']]['rows'] ?? []))->first(fn ($r) => ($r['comment'] ?? null) === self::capKey($mac));
                if ($row !== null && $item['status'] === 'present' && ! in_array('cfg-guest', self::listValue($row['slave-configurations'] ?? ''), true)) {
                    $item['status'] = 'differs';
                    $item['diff']['slave-configurations'] = ['+cfg-guest', (string) ($row['slave-configurations'] ?? '')];
                }
                unset($item);
            }
        }
        // Mode local: the single virtual AP "wifi-guest" of 0.6.0 to 0.12.6 (no comment) on a selected master
        // blocks nothing, but the Wi-Fi block would add a second one: adopt it with the key of the old name,
        // the block then renames it to wifi-guest-<master>.
        $wifiRows = (array) ($menus['/interface wifi']['rows'] ?? []);
        foreach ($g['wifi_masters']['local'] ?? [] as $master) {
            $vk = self::key('wifi', self::guestInterface($master));
            if (($out['wifi'][$vk]['status'] ?? null) === 'missing') {
                $old = $this->unmanaged($wifiRows, '/interface wifi', self::key('wifi', 'wifi-guest'), ['name' => 'wifi-guest', 'master-interface' => $master]);
                if ($old['status'] === 'unmanaged') {
                    $out['wifi'][$vk] = $old + $out['wifi'][$vk];
                }
            }
        }
        // Hotspot server and profile have no comment: identified by name
        $dnsName = self::token(((array) $site->setting('hotspot_hosts', []))[0] ?? null);
        $out['dns'] = ['dns:hotspot-name' => $this->hotspotNameEntry($all['/ip dns static'] ?? null, $dnsName)] + $out['dns'];
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
        // Wi-Fi objects of modes no longer selected (mode switch, decision 2026-11-02): "leftover", clean-up block per router
        foreach ($this->wifiLeftovers($g, $menus) as $id => $item) {
            $out['wifi'][$id] = ['path' => $item['path'], 'name' => $item['name'], 'status' => 'leftover', 'diff' => []];
        }
        // CAPsMAN: cfg-guest in the slave configurations of the selected masters' provisioning rules (not with
        // a CAP selection: then only the own rules per CAP carry it)
        foreach (array_intersect($g['wifi_modes'], Site::CAPSMAN_MODES) as $mode) {
            if (($g['wifi_caps'][$mode] ?? []) !== []) {
                continue;
            }
            $path = self::wifiPath($mode).' provisioning';
            foreach ($g['wifi_masters'][$mode] ?? [] as $master) {
                $id = self::wifiId(self::slaveKey($master), $path, $g['wifi_modes']);
                $out['wifi'][$id] = ['path' => $path, 'name' => $master] + ($id !== self::slaveKey($master) ? ['key' => self::slaveKey($master)] : [])
                    + $this->compareSlaves($menus[$path] ?? null, $master);
            }
        }

        return $out;
    }

    /**
     * Wi-Fi objects of an earlier selection that the Wi-Fi block of the selected modes no longer covers (decision
     * 2026-11-02, mode switch, e.g. CAPsMAN to local radio): per package path whose CAPsMAN mode is not selected,
     * the own CAP rules and the rules of others that still list the own cfg-guest (CAPs keep broadcasting the guest
     * SSID); without mode local the own virtual APs "wifi-guest[-<master>]"; without any mode of the path the own
     * dp-guest, sec-guest and cfg-guest. Own = exact key or legacy comment, like the uninstall; objects of others are
     * never listed. A path whose menus are missing on the router (package not installed) has none.
     *
     * @return array<string, array{path: string, name: ?string, remove: string}> state id "leftover:<menu>:<object>" =>
     *                                                                           item; remove = kind of clean-up
     */
    private function wifiLeftovers(array $g, array $menus): array
    {
        $rows = fn (string $menu) => is_array($menus[$menu] ?? null) && ! isset($menus[$menu]['error']) ? (array) ($menus[$menu]['rows'] ?? []) : [];
        $own = fn (array $row, string $key) => in_array($comment = (string) ($row['comment'] ?? ''), [$key, self::NAME], true)
            || str_starts_with($comment, self::NAME.': ');
        $out = [];
        foreach (['capsman1' => '/caps-man', 'capsman2' => '/interface wifi'] as $capsman => $path) {
            $pathModes = $path === '/caps-man' ? ['capsman1'] : ['capsman2', 'local'];
            $ownCfg = collect($rows("{$path} configuration"))->contains(fn ($r) => ($r['name'] ?? null) === 'cfg-guest' && $own($r, self::key('wifi', 'cfg-guest')));
            if (! in_array($capsman, $g['wifi_modes'], true)) {
                foreach ($rows("{$path} provisioning") as $r) {
                    $ident = (string) ($r['.id'] ?? '');
                    if (self::isCapRule($r)) {
                        $out["leftover:{$path} provisioning:".$r['comment']] = ['path' => "{$path} provisioning", 'name' => (string) ($r['radio-mac'] ?? '') ?: null, 'remove' => 'cap-rule'];
                    } elseif ($ownCfg && in_array('cfg-guest', self::listValue($r['slave-configurations'] ?? ''), true)) {
                        $out["leftover:{$path} provisioning:slave-{$ident}"] = ['path' => "{$path} provisioning", 'name' => (string) ($r['master-configuration'] ?? '') ?: null, 'remove' => 'slave'];
                    }
                }
            }
            if ($path === '/interface wifi' && ! in_array('local', $g['wifi_modes'], true)) {
                foreach ($rows('/interface wifi') as $r) {
                    $name = (string) ($r['name'] ?? '');
                    if (preg_match('/^wifi-guest(-[A-Za-z0-9._-]{1,48})?\z/', $name) === 1 && $own($r, self::key('wifi', $name))) {
                        $out["leftover:/interface wifi:{$name}"] = ['path' => '/interface wifi', 'name' => $name, 'remove' => 'virtual-ap'];
                    }
                }
            }
            if (array_intersect($pathModes, $g['wifi_modes']) === []) {
                foreach (['datapath' => 'dp-guest', 'security' => 'sec-guest', 'configuration' => 'cfg-guest'] as $menu => $name) {
                    if (collect($rows("{$path} {$menu}"))->contains(fn ($r) => ($r['name'] ?? null) === $name && $own($r, self::key('wifi', $name)))) {
                        $out["leftover:{$path} {$menu}:{$name}"] = ['path' => "{$path} {$menu}", 'name' => $name, 'remove' => 'object'];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * One-time clean-up of the Wi-Fi objects of an earlier selection found in a stored state (wifiLeftovers(),
     * decision 2026-11-02), null when there are none. Same commands as the network uninstall, per package path in
     * its order: own CAP rules, cfg-guest out of the rules of others (approved exception, only while the own
     * cfg-guest exists), own virtual APs by name plus key, then the own objects. Only paths with leftovers, so
     * every menu of the script exists on that router.
     */
    public function leftoverCommands(Site $site, array $state): ?string
    {
        $g = $this->guestValues($site);
        $leftovers = collect($this->wifiLeftovers($g, self::ownWifiComments(self::withoutDynamic((array) ($state['menus'] ?? [])))));
        if ($leftovers->isEmpty()) {
            return null;
        }
        $n = self::NAME;
        $out = ["# Clean-up of an earlier Wi-Fi mode: objects of {$n} found by the router state query that the selected",
            '# Wi-Fi modes no longer use. Removes only own objects (comment key or legacy comment) and takes cfg-guest out',
            '# of the provisioning rules of a CAPsMAN package whose mode is not selected (other slaves kept, nothing else',
            '# of the rules changed); objects of others are not touched. Run once, then query the router state again.'];
        foreach (['/caps-man', '/interface wifi'] as $path) {
            $here = $leftovers->filter(fn ($l) => str_starts_with($l['path'], $path.' ') || $l['path'] === $path);
            if ($here->isEmpty()) {
                continue;
            }
            if ($here->contains('remove', 'cap-rule')) {
                $out[] = $this->wifiCapRulesRemove($path);
            }
            if ($here->contains('remove', 'slave')) {
                $out[] = $this->wifiSlaveRemove($path, self::owned(self::key('wifi', 'cfg-guest')));
            }
            foreach ($here->where('remove', 'virtual-ap') as $ap) {
                $out[] = '/interface wifi remove [find where name="'.$ap['name'].'" && '.self::owned(self::key('wifi', $ap['name'])).']';
            }
            if ($here->contains('remove', 'object')) {
                $out[] = '# only if created for the guest network: configuration, security, datapath';
                $out[] = self::wifiObjectsRemove($path);
            }
        }

        return implode("\n", $out);
    }

    /** State without the dynamic rows of DYNAMIC_MENUS. */
    private static function withoutDynamic(array $menus): array
    {
        foreach (self::DYNAMIC_MENUS as $path) {
            if (isset($menus[$path]['rows']) && is_array($menus[$path]['rows'])) {
                $menus[$path]['rows'] = array_values(array_filter($menus[$path]['rows'], fn ($r) => ! self::isDynamic($r)));
            }
        }

        return $menus;
    }

    private static function isDynamic(mixed $row): bool
    {
        return is_array($row) && in_array($row['dynamic'] ?? null, [true, 'true', 'yes'], true);
    }

    /**
     * DNS entry of the hotspot name (decision 2026-11-02): no own entry any more, RouterOS adds a dynamic
     * one for the dns-name of the hotspot profile. "present" when any entry with that name exists (info
     * "dynamic" when it is the router's), "legacy" when an own static entry of earlier versions (key
     * guest-portal:dns:hotspot-name or legacy comment) exists (removed by the next run of the DNS block).
     *
     * @return array{path: string, name: ?string, status: string, diff: array, info?: string}
     */
    private function hotspotNameEntry(?array $menu, string $dnsName): array
    {
        $item = ['path' => '/ip dns static', 'name' => $dnsName !== '' && ! str_contains($dnsName, '<') ? $dnsName : null];
        if ($menu === null || isset($menu['error']) || $item['name'] === null) {
            return $item + ['status' => 'unknown', 'diff' => []];
        }
        $rows = array_values(array_filter((array) ($menu['rows'] ?? []), fn ($r) => is_array($r) && ($r['name'] ?? null) === $dnsName));
        $n = self::NAME;
        foreach ($rows as $row) {
            $comment = (string) ($row['comment'] ?? '');
            if (! self::isDynamic($row) && ($comment === self::key('dns', 'hotspot-name') || $comment === $n || str_starts_with($comment, $n.': '))) {
                return $item + ['status' => 'legacy', 'diff' => []];
            }
        }
        if ($rows === []) {
            return $item + ['status' => 'missing', 'diff' => []];
        }

        return $item + ['status' => 'present', 'diff' => []]
            + (collect($rows)->contains(fn ($r) => self::isDynamic($r)) ? ['info' => 'dynamic'] : []);
    }

    /**
     * A wifi package configuration without an own comment reports the comment of its datapath (or, without
     * one, of its security) as its own (RouterOS 7.24.5, verified on the CHR; the export shows none). Such
     * an inherited comment is cleared here and the row marked "inherited", so it is never taken for an own
     * key or legacy comment: the Wi-Fi block's find would stop matching it as soon as it sets the
     * datapath's key. Assumption: an own comment equal to the datapath's/security's one counts as inherited.
     */
    private static function ownWifiComments(array $menus): array
    {
        $path = '/interface wifi configuration';
        if (! isset($menus[$path]['rows'])) {
            return $menus;
        }
        $comments = fn (string $menu) => array_column(array_filter((array) ($menus[$menu]['rows'] ?? []), fn ($r) => isset($r['name'])), 'comment', 'name');
        $dp = $comments('/interface wifi datapath');
        $sec = $comments('/interface wifi security');
        foreach ($menus[$path]['rows'] as $i => $row) {
            $comment = (string) ($row['comment'] ?? '');
            $refs = [$dp[(string) ($row['datapath'] ?? '')] ?? null, $sec[(string) ($row['security'] ?? '')] ?? null];
            if ($comment !== '' && in_array($comment, $refs, true)) {
                $menus[$path]['rows'][$i] = ['comment' => '', 'inherited' => true] + $row;
            }
        }

        return $menus;
    }

    /** Own provisioning rule of the guest SSID per CAP (exact key pattern "guest-portal:wifi:cap-<12 hex>"). */
    private static function isCapRule(array $row): bool
    {
        return preg_match('/^'.preg_quote(self::NAME, '/').':wifi:cap-[0-9a-f]{12}\z/', (string) ($row['comment'] ?? '')) === 1;
    }

    /**
     * List property of a state row ("a,b" or "a;b") as list of non-empty entries.
     *
     * @return list<string>
     */
    private static function listValue(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', str_replace(';', ',', (string) $value))), fn ($v) => $v !== ''));
    }

    /** Status key (not a comment) of "cfg-guest appended to the provisioning rules of master configuration $master". */
    public static function slaveKey(string $master): string
    {
        return self::key('wifi', 'slave-'.$master);
    }

    /**
     * Provisioning rules of one master configuration: present when every rule lists cfg-guest as a slave,
     * missing when there is no rule or a rule without it (the Wi-Fi block appends it).
     *
     * @return array{status: string, diff: array<string, array{0: string, 1: string}>}
     */
    private function compareSlaves(?array $menu, string $master): array
    {
        if ($menu === null || isset($menu['error'])) {
            return ['status' => 'unknown', 'diff' => []];
        }
        $rules = array_values(array_filter((array) ($menu['rows'] ?? []), fn ($r) => ($r['master-configuration'] ?? null) === $master
            && ! self::isCapRule($r)));
        foreach ($rules as $rule) {
            $slaves = array_map('trim', explode(',', str_replace(';', ',', (string) ($rule['slave-configurations'] ?? ''))));
            if (! in_array('cfg-guest', $slaves, true)) {
                return ['status' => 'missing', 'diff' => ['slave-configurations' => ['+cfg-guest', (string) ($rule['slave-configurations'] ?? '')]]];
            }
        }

        return ['status' => $rules === [] ? 'missing' : 'present', 'diff' => []];
    }

    /**
     * Masters selectable for the guest Wi-Fi per mode from the stored router states (decision
     * 2026-11-01): local = wifi interfaces without a master interface (radios), capsman1/capsman2 = the
     * master configurations of the provisioning rules. Own guest objects are left out.
     *
     * @param  iterable<NasClient>  $routers
     * @return array<string, list<string>>
     */
    public function wifiMasterOptions(iterable $routers): array
    {
        $out = ['local' => [], 'capsman1' => [], 'capsman2' => []];
        foreach ($routers as $nas) {
            $menus = is_array($nas->router_state) ? (array) ($nas->router_state['menus'] ?? []) : [];
            $rows = fn (string $menu) => (array) ($menus[$menu]['rows'] ?? []);
            foreach ($rows('/interface wifi') as $r) {
                if (($r['master-interface'] ?? '') === '') {
                    $out['local'][] = (string) ($r['name'] ?? '');
                }
            }
            foreach (['capsman1' => '/caps-man provisioning', 'capsman2' => '/interface wifi provisioning'] as $mode => $menu) {
                foreach ($rows($menu) as $r) {
                    if (! self::isCapRule($r)) {
                        $out[$mode][] = (string) ($r['master-configuration'] ?? '');
                    }
                }
            }
        }

        return array_map(function (array $list) {
            $list = array_values(array_unique(array_filter($list, fn ($m) => Site::validWifiMaster($m))));
            sort($list, SORT_NATURAL);

            return $list;
        }, $out);
    }

    /**
     * Status of one object: matched by the exact comment key (or, without a key, by name); legacy
     * comments of earlier scripts count as "legacy" (present, replaced on the next run). For the guarded
     * adds (GUARDED: added only when missing) an equal entry of others counts as "foreign" (used as is).
     *
     * @return array{status: string, diff: array<string, array{0: string, 1: string}>}
     */
    private function compareObject(?array $menu, ?string $key, array $expect, ?string $ident, ?string $path = null): array
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
            $noComment = $comment === '' && in_array($key, self::LEGACY_NO_COMMENT, true);
            if ($comment !== $n && ! str_starts_with($comment, $n.': ') && ! $noComment) {
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

            return ['status' => 'missing', 'diff' => []];
        }

        return $ident !== null && $path !== null && isset($expect[$ident])
            ? $this->unmanaged($rows, $path, $key, [$ident => $expect[$ident]] + (self::ADOPT_MATCH[$key] ?? []))
            : ['status' => 'missing', 'diff' => []];
    }

    /**
     * Object of an earlier version created without a comment (0.6.0 to 0.12.6, or by hand following
     * docs/guest-network-setup.md): a row matching the identifying properties with an empty comment
     * makes the script's add fail ("already exists") while find matches nothing. Status "unmanaged"
     * with a one-time adopt command for the admin (decision 2026-11-01): it sets the comment key only on
     * that row and only while its comment is empty; the scripts never adopt objects themselves. Several
     * matching rows: no command (ambiguous, to be resolved by hand). Rows with any other comment are not
     * ours and keep the status "missing".
     *
     * @param  array<string, string>  $match  property => value identifying the object
     * @return array{status: string, diff: array<string, array{0: string, 1: string}>, adopt?: ?string}
     */
    private function unmanaged(array $rows, string $path, string $key, array $match): array
    {
        $found = array_values(array_filter($rows, function ($r) use ($match) {
            foreach ($match as $prop => $value) {
                if ((string) ($r[$prop] ?? '') !== $value) {
                    return false;
                }
            }

            return trim((string) ($r['comment'] ?? '')) === '';
        }));
        if ($found === []) {
            return ['status' => 'missing', 'diff' => []];
        }
        $cond = implode(' && ', array_map(fn ($p, $v) => $p.'="'.$v.'"', array_keys($match), $match));
        // wifi package configuration: also while it only shows the comment of its datapath or security
        // (ownWifiComments()), evaluated on the router when the command runs (order of the commands free)
        $empty = '!comment';
        if ($path === '/interface wifi configuration' && count($found) === 1) {
            foreach (['datapath', 'security'] as $ref) {
                $name = (string) ($found[0][$ref] ?? '');
                if ($name !== '' && preg_match('/^[A-Za-z0-9._-]+$/D', $name)) {
                    $empty .= " || comment=[/interface wifi {$ref} get [find where name=\"{$name}\"] comment]";
                }
            }
            $empty = $empty === '!comment' ? $empty : "({$empty})";
        }

        // Dynamic rows of the hotspot are filtered before; the command skips them on the router too
        $static = in_array($path, self::DYNAMIC_MENUS, true) ? ' && !dynamic' : '';

        return ['status' => 'unmanaged', 'diff' => [],
            'adopt' => count($found) === 1 ? "{$path} set [find where {$cond} && {$empty}{$static}] comment=\"{$key}\"" : null];
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

    /**
     * Why the RouterOS container cannot run on a router according to its stored state (hint of the container
     * step, decision 2026-11-02): "package" when both /container menus are missing (no container package),
     * "device_mode" when the device mode does not allow containers, null when fine or unknown.
     */
    public function containerProblem(array $state): ?string
    {
        $menus = (array) ($state['menus'] ?? []);
        $missing = fn (string $menu) => is_array($menus[$menu] ?? null) && isset($menus[$menu]['error']);
        if ($missing('/container mounts') && $missing('/container envs')) {
            return 'package';
        }
        $mode = $menus['/system device-mode']['rows'][0]['container'] ?? null;

        return in_array($mode, ['no', 'false', false], true) ? 'device_mode' : null;
    }

    public function deriveSetup(Site $site, array $state): array
    {
        $menus = (array) ($state['menus'] ?? []);
        $rows = fn (string $menu) => (array) ($menus[$menu]['rows'] ?? []);
        $own = fn (string $menu, string $key) => collect($rows($menu))->first(fn ($r) => ($r['comment'] ?? null) === $key);
        $saved = (array) $site->setting('setup', []);
        $out = [];
        $set = function (string $field, mixed $value, string $source) use (&$out, $saved) {
            if (($saved[$field] ?? '') === '' && $value !== null && $value !== '' && $value !== [] && ! isset($out[$field])) {
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
        // Wi-Fi modes (decision 2026-11-02, a list): every mode with own objects, else the enabled CAPsMAN
        // of exactly one package. Not derived when the site has saved modes (also the single one of earlier versions).
        $own = array_values(array_filter([
            $capsCfg !== null ? 'capsman1' : null,
            $wifiCfg !== null && $enabled('/interface wifi capsman') ? 'capsman2' : null,
            collect($rows('/interface wifi'))->contains(fn ($r) => str_starts_with((string) ($r['comment'] ?? ''), self::key('wifi', 'wifi-guest'))) ? 'local' : null,
        ]));
        if (! Site::hasSavedWifiModes($saved)) {
            if ($own !== []) {
                $set('wifi_modes', $own, 'own');
            } elseif ($enabled('/caps-man manager') && ! $enabled('/interface wifi capsman')) {
                $set('wifi_modes', ['capsman1'], 'capsman1');
            } elseif ($enabled('/interface wifi capsman') && ! $enabled('/caps-man manager')) {
                $set('wifi_modes', ['capsman2'], 'capsman2');
            }
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
     * worst status of the comment keys on the line ("unmanaged" > "missing" > "update" > "ok"), the hotspot server
     * and profile by "add name=guest-portal" in their menu, continuation lines (trailing "\") and the
     * lines inside a "{ ... }" group opened by a line with a state inherit it. Comment lines and lines
     * without a known key stay null. Display only: the script itself is not changed.
     *
     * Uninstall scripts ($uninstall): "remove" where an own object exists (the line would delete it),
     * "kept" where only an object without comment exists (not deleted, except a "!comment" match),
     * null where nothing exists or the state is unknown.
     *
     * @param  array<string, string>  $statusByKey  comment key (or hotspot:server|profile) => compare status
     * @return list<?string> one entry per line of $script: unmanaged|missing|update|ok|remove|kept|null
     */
    public function lineStatuses(string $script, array $statusByKey, bool $uninstall = false): array
    {
        $class = fn (?string $status) => $uninstall
            ? match ($status) {
                'unmanaged' => 'kept', 'present', 'differs', 'legacy' => 'remove', default => null
            }
        : match ($status) {
            'unmanaged' => 'unmanaged', 'missing' => 'missing', 'differs', 'legacy' => 'update', 'present', 'foreign' => 'ok', default => null
        };
        $rank = ['ok' => 1, 'kept' => 1, 'update' => 2, 'missing' => 3, 'remove' => 3, 'unmanaged' => 4];
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
        $removes = [];
        $menus = [];
        $own = [];
        foreach (explode("\n", $script) as $line) {
            $t = trim($line);
            $status = null;
            $cmdMenu = null;
            if ($t !== '' && $t[0] !== '#') {
                // Object part: also master names (virtual APs "wifi-guest-<master>")
                preg_match_all('/"('.$n.':[a-z0-9-]+:[A-Za-z0-9._-]+)"/', $line, $m);
                // Slave append of a provisioning rule (CAPsMAN): status key of its master configuration
                preg_match_all('/provisioning find where master-configuration="([A-Za-z0-9._-]+)"/', $line, $p);
                $keys = [...$m[1], ...array_map(fn ($master) => self::slaveKey($master), $p[1])];
                $status = $worst(array_map(fn ($key) => $class($statusByKey[$key] ?? null), array_unique($keys)));
                // Uninstall: "|| !comment" also deletes the object without comment
                if ($status === 'kept' && str_contains($line, '!comment')) {
                    $status = 'remove';
                }
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
                $byNameCmd = $uninstall ? '/^remove \\[find (where )?name="?'.$n.'"?\\]/' : '/^add name='.$n.'( |$)/';
                if ($status === null && isset($byName[$cmdMenu]) && preg_match($byNameCmd, $rest)) {
                    $status = $class($statusByKey[$byName[$cmdMenu]] ?? null);
                }
                $own[count($out)] = $status;
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
                if ($t[0] !== '/' || $rest !== '') {
                    $menus[count($out)] = $cmdMenu;
                }
                if (str_starts_with($rest.' ', 'remove ')) {
                    $removes[count($out)] = $cmdMenu;
                }
            } else {
                $continued = null;
            }
            $out[] = $status;
        }
        // A remove line shows the worst state of the later commands of its menu (the add/set it prepares),
        // and only if something has to be done there: no colour when they are all "ok".
        // Uninstall: only lines that delete something ("remove [", also inside :if/:foreach), by their own keys;
        // no inheritance (a foreach that only resets a DHCP option or a provisioning rule deletes nothing)
        if ($uninstall) {
            foreach (explode("\n", $script) as $i => $line) {
                $out[$i] = str_contains((string) preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '', $line), 'remove [') ? ($own[$i] ?? null) : null;
            }

            return $out;
        }
        foreach ($removes as $i => $removeMenu) {
            $later = [];
            foreach ($menus as $j => $m) {
                if ($j > $i && $m === $removeMenu && ! isset($removes[$j])) {
                    $later[] = $out[$j];
                }
            }
            $state = $worst($later);
            $out[$i] = in_array($state, ['missing', 'update', 'unmanaged'], true) ? $state : null;
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
                    if ($v !== '' && $v !== 'cfg-guest' && ! str_starts_with($v, 'wifi-guest')) {
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
            $masters = $this->wifiMasterOptions([$nas]);
            $add('WIFI_INTERFACE', $masters['local']);
            $add('BRIDGE', $bridges);
            $add('CONTAINER_BRIDGE', $bridges);
            $add('GUEST_POOL', $col('/ip pool', 'name'));
            // Master configurations of the provisioning rules (the slave list is computed by the script)
            $add('MASTER_CFG', [...$masters['capsman1'], ...$masters['capsman2']]);
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
            "# The service must use the router certificate \"{$n}\" (imported by the certificate script in the router script):",
            '# the portal pins its key. Not changed by this script (not an own object); check, and set it if needed:',
            "/ip service print where name={$service}",
            "# /ip service set {$service} certificate={$n} disabled=no port={$port}",
        ]);
    }
}
