<?php

namespace App\Federation;

use RuntimeException;

/** Pairing refused; $status is the HTTP status for the join endpoint. */
class PairingException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
