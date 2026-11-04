<?php

namespace App\Sms;

use RuntimeException;

/**
 * Delivery error of an SMS driver (CONCEPT.md §7.2 B, §7.3). The message carries the provider name and the
 * HTTP status or the provider's error code only, never the number or a secret.
 *
 * `$recipient` marks a rejection of this message itself (invalid or blocked number, request refused as
 * malformed): the next provider would refuse it as well, so the failover stops and the provider is not
 * put into cooldown. Every other failure (connection, timeout, 5xx, 429, authentication, credit) lets the
 * next provider take over.
 */
class SmsFailure extends RuntimeException
{
    public function __construct(string $message, public readonly bool $recipient = false)
    {
        parent::__construct($message);
    }

    /**
     * Failure from an HTTP status: 400 and 422 count as a rejection of the message (the provider did not
     * say more), everything else as a provider failure.
     */
    public static function http(string $provider, int $status, ?string $code = null, ?bool $recipient = null): self
    {
        return new self($provider.' send failed: HTTP '.$status.($code !== null && $code !== '' ? ' (code '.self::clean($code).')' : ''),
            $recipient ?? in_array($status, [400, 422], true));
    }

    /** Failure from a provider error code in a successful HTTP answer. */
    public static function code(string $provider, string|int|null $code, bool $recipient = false): self
    {
        return new self($provider.' rejected the message: code '.self::clean($code), $recipient);
    }

    /** Provider error codes are echoed only as a short token (letters, digits, `_.-`). */
    public static function clean(string|int|null $code): string
    {
        $code = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $code);

        return $code === '' ? 'unknown' : substr($code, 0, 40);
    }
}
