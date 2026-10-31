<?php

namespace App\Nas\RouterOs;

use RuntimeException;

/**
 * Failure of a read-only router API call. kind: config (access incomplete), connect, timeout, tls
 * (certificate pin mismatch or handshake), auth (login refused), denied (missing policy), protocol
 * (unexpected answer), menu (menu not available on this router, e.g. package missing; per menu only).
 * The detail is technical English text without credentials.
 */
class RouterOsException extends RuntimeException
{
    public const KINDS = ['config', 'connect', 'timeout', 'tls', 'auth', 'denied', 'protocol', 'menu'];

    public function __construct(public readonly string $kind, string $detail)
    {
        parent::__construct($detail);
    }

    /** @return array{kind: string, detail: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'detail' => mb_substr($this->getMessage(), 0, 300)];
    }
}
