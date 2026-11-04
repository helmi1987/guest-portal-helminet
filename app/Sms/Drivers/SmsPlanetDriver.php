<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * SMS Planet (smsplanet.pl; CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://smsplanet.pl/doc/slate/index.html
 *
 * POST https://api2.smsplanet.pl/sms, header Authorization: Bearer <API token>, form fields (UTF-8) from
 * (sender name, e.g. "TEST" or an approved sender), to (+48600111222 style), msg. Answer {messageId}
 * (a "B-" prefix means buffered); errors {errorMsg, errorCode} (e.g. 101 invalid key). Invalid numbers are
 * skipped by the provider, so a missing messageId counts as failure; no code is documented as "invalid
 * number", so every error lets the next provider take over (HTTP 400/422 without code excepted).
 */
class SmsPlanetDriver extends HttpSmsDriver
{
    public const API = 'https://api2.smsplanet.pl/sms';

    public static function fields(): array
    {
        return [
            'token' => ['secret' => true, 'required' => true],
            'sender' => ['required' => true],
        ];
    }

    protected function provider(): string
    {
        return 'SMS Planet';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $response = $this->request($channel, fn ($http) => $http->asForm()->acceptJson()->withToken($channel->conf('token'))
            ->post(self::API, ['from' => $channel->conf('sender'), 'to' => $phoneE164, 'msg' => $text]));
        $this->failUnlessSuccessful($response, $response->json('errorCode'), []);

        $id = self::id($response->json('messageId'));
        if ($id === null) {
            throw $this->rejected($response->json('errorCode'));
        }

        return $id;
    }
}
