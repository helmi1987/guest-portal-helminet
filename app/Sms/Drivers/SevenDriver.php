<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * seven.io (formerly sms77; CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://docs.seven.io/en/rest-api/endpoints/sms
 *
 * POST https://gateway.seven.io/api/sms, header X-Api-Key, Accept: application/json; fields to (E.164,
 * sent without "+"), text, from (optional, 11 alphanumeric or 16 digits). Encoding is chosen by seven.io.
 * The answer carries `success` (return code, 100 = accepted) and messages[].id. Return codes 202 (invalid
 * recipient), 407003 (number blocked) and 407004 (unsubscribed) reject the number; all others (900 auth,
 * 500 credit, 600 sending error, ...) let the next provider take over. A bare return code as body (older
 * plain answer) is handled the same way.
 */
class SevenDriver extends HttpSmsDriver
{
    public const API = 'https://gateway.seven.io/api/sms';

    private const RECIPIENT_CODES = [202, 407003, 407004];

    public static function fields(): array
    {
        return [
            'api_key' => ['secret' => true, 'required' => true],
            'from' => [],
        ];
    }

    protected function provider(): string
    {
        return 'seven.io';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $payload = array_filter(['to' => self::digits($phoneE164), 'text' => $text, 'from' => $channel->conf('from')]);
        $response = $this->request($channel, fn ($http) => $http->asJson()->acceptJson()
            ->withHeaders(['X-Api-Key' => $channel->conf('api_key')])->post(self::API, $payload));
        $this->failUnlessSuccessful($response);

        $json = $response->json();
        $code = is_array($json) ? ($json['success'] ?? null) : trim($response->body());
        if ((string) $code !== '100') {
            throw $this->rejected($code, self::RECIPIENT_CODES);
        }

        return self::id($json['messages'][0]['id'] ?? null);
    }
}
