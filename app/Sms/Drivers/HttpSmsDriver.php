<?php

namespace App\Sms\Drivers;

use App\Models\Channel;
use App\Sms\SmsFailure;
use App\Support\OutboundHttp;
use App\Support\Redact;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Shared HTTP handling of the provider drivers (CONCEPT.md §7.3): one client per send (OutboundHttp,
 * TLS verified, 15 s, no redirects), connection errors and redirects as provider failures, error
 * messages with the HTTP status or the provider's error code only (secrets and numbers masked).
 */
abstract class HttpSmsDriver implements SmsDriver
{
    /** Provider name used in error messages. */
    abstract protected function provider(): string;

    /**
     * Send the request built by $build; a connection error, timeout or redirect throws a SmsFailure
     * that lets the next provider take over. The response is returned for any other status.
     *
     * @param  callable(PendingRequest): Response  $build
     */
    protected function request(Channel $channel, callable $build): Response
    {
        try {
            $response = $build(OutboundHttp::client(15));
        } catch (Throwable $e) {
            throw new SmsFailure($this->provider().' send failed: '.Redact::secrets($e->getMessage(), $channel->secretValues()));
        }
        if ($response->redirect()) {
            throw SmsFailure::http($this->provider(), $response->status(), recipient: false);
        }

        return $response;
    }

    /**
     * Throw on a non-2xx answer. With a provider error code and a list of the codes that reject the number
     * (may be empty), the code decides (any other code is a provider failure even with HTTP 400); without
     * code or list, the status decides (SmsFailure::http()).
     *
     * @param  list<string|int>|null  $recipientCodes
     */
    protected function failUnlessSuccessful(Response $response, string|int|null $code = null, ?array $recipientCodes = null): void
    {
        if ($response->successful()) {
            return;
        }
        $known = $code !== null && $code !== '';
        $recipient = $known && $recipientCodes !== null ? in_array((string) $code, array_map('strval', $recipientCodes), true) : null;

        throw SmsFailure::http($this->provider(), $response->status(), $known ? (string) $code : null, $recipient);
    }

    /** Failure from an error code in a 2xx answer; $recipientCodes reject the number (no failover). */
    protected function rejected(string|int|null $code, array $recipientCodes = []): SmsFailure
    {
        return SmsFailure::code($this->provider(), $code, in_array((string) $code, array_map('strval', $recipientCodes), true));
    }

    /** International number without "+" (e.g. 41790000000). */
    protected static function digits(string $phoneE164): string
    {
        return ltrim($phoneE164, '+');
    }

    /** Whether the text fits the GSM 03.38 default alphabet (incl. extension table); else UCS-2 is needed. */
    public static function isGsm7(string $text): bool
    {
        $gsm = '@£$¥èéùìòÇ'."\n".'Øø'."\r".'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà'
            .'^{}\\[~]|€'."\f";

        foreach (mb_str_split($text) as $char) {
            if (! str_contains($gsm, $char)) {
                return false;
            }
        }

        return true;
    }

    /** Scalar provider id as string, else null. */
    protected static function id(mixed $id): ?string
    {
        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }
}
