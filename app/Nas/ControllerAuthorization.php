<?php

namespace App\Nas;

use Illuminate\Support\Carbon;

/**
 * Result of AuthorizesViaController::authorizeClient(): granted or not, when the controller ends the
 * authorization itself, the controller's id of the client and a short raw status for logs (never
 * credentials). $error is a stable reason key when not granted (e.g. "not_connected", "not_guest",
 * "wrong_ap", "controller").
 */
final readonly class ControllerAuthorization
{
    public function __construct(
        public bool $granted,
        public ?Carbon $expiresAt = null,
        public ?string $providerId = null,
        public string $rawStatus = '',
        public ?string $error = null,
        public ?string $ip = null,
    ) {}

    public static function failed(string $error, string $rawStatus = ''): self
    {
        return new self(false, rawStatus: $rawStatus, error: $error);
    }
}
