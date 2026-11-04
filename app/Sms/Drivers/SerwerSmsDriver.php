<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * SerwerSMS.pl HTTPS API v2 (CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://dev.serwersms.pl/en/https-api-v2/sending-sms-messages-with-the-same-content
 *
 * POST https://api2.serwersms.pl/messages/send_sms (JSON is the default format), JSON {username, password,
 * phone (international, e.g. +48500600700), text, sender (empty = ECO, set = FULL; must be approved),
 * utf (non-GSM characters, FULL only), details: true}. Success {success: true, queued, unsent, items:
 * [{id, phone, status, error_code}]}; an item with status "unsent" and error_code 3103 ("incorrect
 * number") rejects the number; errors {error: {code, type, message}}.
 */
class SerwerSmsDriver extends HttpSmsDriver
{
    public const API = 'https://api2.serwersms.pl/messages/send_sms';

    public static function fields(): array
    {
        return [
            'username' => ['required' => true],
            'password' => ['secret' => true, 'required' => true],
            'from' => [],
        ];
    }

    protected function provider(): string
    {
        return 'SerwerSMS';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $payload = [
            'username' => $channel->conf('username'),
            'password' => $channel->conf('password'),
            'phone' => $phoneE164,
            'text' => $text,
            'details' => true,
        ];
        if ($channel->conf('from')) {
            $payload['sender'] = $channel->conf('from');
            $payload['utf'] = ! self::isGsm7($text);
        }
        $response = $this->request($channel, fn ($http) => $http->asJson()->acceptJson()->post(self::API, $payload));
        $this->failUnlessSuccessful($response, $response->json('error.code'));
        if ($response->json('success') !== true) {
            throw $this->rejected($response->json('error.code'));
        }
        $item = $response->json('items.0') ?? [];
        if (($item['status'] ?? null) === 'unsent') {
            throw $this->rejected($item['error_code'] ?? null, [3103]);
        }

        return self::id($item['id'] ?? null);
    }
}
