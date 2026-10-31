<?php

namespace App\Nas\RouterOs;

use App\Federation\PeerCertificate;
use App\Models\NasClient;

/**
 * Read-only client for a router's configured API access (CONCEPT.md §9, decision 2026-10-31).
 * TLS trust: the public key pin of the router (field api_pin, "sha256//<base64>"), otherwise the pin
 * of the router certificate this portal manages (portal.router_cert.cert), which the router uses for
 * www-ssl / api-ssl once the certificate script imported it.
 */
class RouterOsClients
{
    public function __construct(private readonly ApiConnector $connector) {}

    /** @throws RouterOsException */
    public function for(NasClient $nas, ?float $deadline = null): RouterOsClient
    {
        if (! $nas->hasApiAccess()) {
            throw new RouterOsException('config', 'router API access is not configured');
        }
        $pin = self::pin($nas);
        $config = config('portal.router_api');

        return $nas->api_type === 'api-ssl'
            ? new ApiSslClient($this->connector, $nas->api_host, $nas->apiPort(), $nas->api_user, $nas->api_password, $pin,
                (int) $config['connect_timeout'], (int) $config['timeout'], (int) $config['max_rows'], $deadline,
                (int) ($config['max_bytes'] ?? 8388608), (int) ($config['max_word'] ?? 65536))
            : new RestClient($nas->api_host, $nas->apiPort(), $nas->api_user, $nas->api_password, $pin,
                (int) $config['connect_timeout'], (int) $config['timeout'], (int) ($config['max_bytes'] ?? 8388608));
    }

    /** @throws RouterOsException */
    public static function pin(NasClient $nas): string
    {
        if (PeerCertificate::isPin($nas->api_pin)) {
            return $nas->api_pin;
        }
        $path = (string) config('portal.router_cert.cert');
        $pin = is_readable($path) ? PeerCertificate::pin((string) file_get_contents($path)) : null;
        if ($pin === null) {
            throw new RouterOsException('tls', 'no router certificate installed in the portal and no pin configured');
        }

        return $pin;
    }
}
