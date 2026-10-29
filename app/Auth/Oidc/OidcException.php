<?php

namespace App\Auth\Oidc;

use App\Models\User;
use RuntimeException;

/**
 * A failed OIDC login. `reason` is a short code for the audit log; the message holds details for the
 * application log only (never shown to the user).
 */
class OidcException extends RuntimeException
{
    /** @param  bool  $invalidateSessions  end the user's existing sessions (e.g. lost all portal groups) */
    public function __construct(
        public readonly string $reason,
        string $detail = '',
        public readonly ?User $user = null,
        public readonly bool $invalidateSessions = false,
    ) {
        parent::__construct($detail !== '' ? $detail : $reason);
    }
}
