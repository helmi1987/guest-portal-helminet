<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * SMS provider driver (CONCEPT.md §7.3). Outbound only; implementations throw on delivery errors.
 */
interface SmsDriver
{
    /**
     * Config fields for the admin form: name => [secret => bool, required => bool].
     *
     * @return array<string, array{secret?: bool, required?: bool}>
     */
    public static function fields(): array;

    /** Send one SMS to an E.164 number. Returns the provider's message id, if any. */
    public function send(Channel $channel, string $phoneE164, string $text): ?string;
}
