<?php

namespace App\Nas\RouterOs;

/** Byte stream to a RouterOS API-SSL service (TLS established); replaced by a fake in tests. */
interface ApiConnection
{
    /** Public key pin of the server certificate (sha256//<base64>), null if unknown. */
    public function peerPin(): ?string;

    /** @throws RouterOsException */
    public function write(string $bytes): void;

    /**
     * Exactly $length bytes.
     *
     * @throws RouterOsException
     */
    public function read(int $length): string;

    public function close(): void;
}
