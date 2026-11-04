<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * SmsManager (smsmanager.cz, HTTP API; CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://www.smsmanager.cz/api/http
 *
 * GET or POST https://http-api-lts.smsmanager.cz with apikey, number (international, "+" allowed),
 * message, gateway (high / economy / low / direct), sender (optional), type=utf (keep diacritics).
 * Answer text "OK|<message id>|<number>" or "ERROR|<code>"; the error codes are not listed (only
 * ERROR|102 is shown), so every error lets the next provider take over. Sent as POST so key and text stay
 * out of URLs.
 */
class SmsManagerDriver extends HttpSmsDriver
{
    public const API = 'https://http-api-lts.smsmanager.cz';

    public static function fields(): array
    {
        return [
            'api_key' => ['secret' => true, 'required' => true],
            'gateway' => ['options' => ['high', 'economy', 'low', 'direct']],
            'from' => [],
        ];
    }

    protected function provider(): string
    {
        return 'SmsManager';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $form = array_filter(['apikey' => $channel->conf('api_key'), 'number' => $phoneE164, 'message' => $text,
            'gateway' => $channel->conf('gateway', 'high'), 'sender' => $channel->conf('from'),
            'type' => self::isGsm7($text) ? null : 'utf']);
        $response = $this->request($channel, fn ($http) => $http->asForm()->post(self::API, $form));
        $parts = explode('|', trim($response->body()));
        $this->failUnlessSuccessful($response, $parts[0] === 'ERROR' ? ($parts[1] ?? null) : null, []);
        if ($parts[0] !== 'OK') {
            throw $this->rejected($parts[1] ?? null);
        }

        return self::id($parts[1] ?? null);
    }
}
