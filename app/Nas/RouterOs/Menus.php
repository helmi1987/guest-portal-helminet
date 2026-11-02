<?php

namespace App\Nas\RouterOs;

/**
 * Menus the read-only client may print, and the guard every call passes (CONCEPT.md §9, decision
 * 2026-10-31). Properties that may carry secrets are refused even though the router hides them from a
 * user without the "sensitive" policy.
 */
final class Menus
{
    public const ALLOWED = [
        '/system resource', '/interface', '/interface bridge', '/interface bridge port', '/interface bridge vlan',
        '/interface vlan', '/interface veth', '/interface list', '/interface list member',
        '/interface wifi', '/interface wifi datapath', '/interface wifi security', '/interface wifi configuration',
        '/interface wifi provisioning', '/interface wifi capsman', '/interface wifi radio',
        '/caps-man manager', '/caps-man datapath', '/caps-man security', '/caps-man configuration', '/caps-man provisioning',
        '/caps-man radio', '/caps-man interface',
        '/ip address', '/ip pool', '/ip dhcp-server', '/ip dhcp-server network', '/ip dhcp-server option',
        '/ip dns static', '/ip hotspot', '/ip hotspot profile', '/ip hotspot walled-garden', '/ip hotspot walled-garden ip',
        '/ip firewall filter', '/ip firewall address-list', '/ip service',
        '/radius', '/system script', '/system scheduler', '/container mounts', '/container envs', '/user', '/user group',
        '/system device-mode',
    ];

    /** Property names never requested (secrets, script sources, keys). */
    private const FORBIDDEN = '/secret|pass|source|token|private|psk|sensitive/i';

    /**
     * Validate one print call; returns the menu as path segments.
     *
     * @return list<string>
     *
     * @throws RouterOsException
     */
    public static function guard(string $menu, array $proplist, array $where): array
    {
        if (! in_array($menu, self::ALLOWED, true)) {
            throw new RouterOsException('config', "menu not allowed: {$menu}");
        }
        if ($proplist === []) {
            throw new RouterOsException('config', 'an explicit property list is required');
        }
        foreach ($proplist as $prop) {
            if (! is_string($prop) || ! preg_match('/^\.?[a-z0-9][a-z0-9.-]*$/D', $prop) || preg_match(self::FORBIDDEN, $prop)) {
                throw new RouterOsException('config', 'property not allowed: '.(is_string($prop) ? $prop : gettype($prop)));
            }
        }
        foreach ($where as $condition) {
            if (! is_string($condition) || ! preg_match('/^[a-z0-9][a-z0-9.-]*=[A-Za-z0-9._:\/,-]*$/D', $condition)
                || preg_match(self::FORBIDDEN, strstr($condition, '=', true))) {
                throw new RouterOsException('config', 'condition not allowed');
            }
        }

        return explode(' ', substr($menu, 1));
    }
}
