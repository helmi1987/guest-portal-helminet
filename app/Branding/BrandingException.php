<?php

namespace App\Branding;

use RuntimeException;

/**
 * Rejected branding upload; the message is a key below lang portal.branding.errors.
 */
class BrandingException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
