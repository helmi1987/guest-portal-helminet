<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * SMSAPI (smsapi.com / smsapi.pl; CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://www.smsapi.com/docs/
 *
 * POST https://api.smsapi.com/sms.do (Polish accounts: https://api.smsapi.pl/sms.do, field api_url),
 * header Authorization: Bearer <OAuth token>, form fields to (international without "+"), message, from
 * (an active sender name; default "Test"), format=json, encoding=utf-8. Success: {count, list: [{id, ...}]};
 * failure: {error: <code>, message} (also with HTTP 200). Code 13 ("no correct phone numbers") rejects the
 * number; 101 (authorisation), 103 (credits), 105 (IP) and others let the next provider take over.
 */
class SmsApiDriver extends HttpSmsDriver
{
    public const API = 'https://api.smsapi.com/sms.do';

    public static function fields(): array
    {
        return [
            'token' => ['secret' => true, 'required' => true],
            'from' => [],
            'api_url' => [],
        ];
    }

    protected function provider(): string
    {
        return 'SMSAPI';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $form = array_filter(['to' => self::digits($phoneE164), 'message' => $text, 'from' => $channel->conf('from'),
            'format' => 'json', 'encoding' => 'utf-8']);
        $response = $this->request($channel, fn ($http) => $http->asForm()->acceptJson()
            ->withToken($channel->conf('token'))->post($channel->conf('api_url', self::API), $form));
        $this->failUnlessSuccessful($response, $response->json('error'), [13]);
        if ($response->json('error') !== null) {
            throw $this->rejected($response->json('error'), [13]);
        }

        return self::id($response->json('list.0.id'));
    }
}
