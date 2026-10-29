<?php

namespace App\Support;

/**
 * Site setting "portal_address": IPv4 of the portal instance as reachable from the router (walled
 * garden, static DNS of the portal name). It must never be the guest gateway (the router itself) or
 * lie inside the guest subnet, otherwise the portal name points at the router.
 */
final class PortalAddress
{
    /**
     * Translated problem of the address against the saved guest network (site setting "setup"),
     * null if it is fine or not set.
     *
     * @param  array<string, mixed>  $setup
     */
    public static function problem(mixed $address, array $setup): ?string
    {
        if ($address === null || $address === '') {
            return null;
        }
        $address = (string) $address;
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return __('portal.setup.invalid_ip');
        }
        $gateway = $setup['gateway'] ?? null;
        if (is_string($gateway) && $gateway === $address) {
            return __('portal.sites.portal_address_is_gateway', ['ip' => $address]);
        }
        $subnet = $setup['subnet'] ?? null;
        if (is_string($subnet) && preg_match('/^([0-9.]+)\/(\d{1,2})$/', $subnet, $m)
            && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int) $m[2] <= 32) {
            $mask = (int) $m[2] === 0 ? 0 : (-1 << (32 - (int) $m[2])) & 0xFFFFFFFF;
            if ((ip2long($address) & $mask) === (ip2long($m[1]) & $mask)) {
                return __('portal.sites.portal_address_in_guest_subnet', ['ip' => $address, 'subnet' => $subnet]);
            }
        }

        return null;
    }
}
