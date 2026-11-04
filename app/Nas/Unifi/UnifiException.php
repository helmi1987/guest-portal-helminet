<?php

namespace App\Nas\Unifi;

use RuntimeException;

/**
 * Error of a UniFi controller call: a stable reason key (connect, timeout, tls, auth, denied, not_found,
 * rate_limited, site_not_found, not_configured, protocol, controller) and the HTTP status when there was
 * one. Messages are redacted (Redact::secrets) and never contain credentials.
 */
final class UnifiException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
