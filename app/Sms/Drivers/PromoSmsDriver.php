<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * PromoSMS (promosms.com; CONCEPT.md §7.3). Not verifiable against public docs on 2026-11-04: the API
 * documentation (https://promosms.com/api/) is not publicly readable; the endpoint answers with
 * {"response": {"status", "price", "sentSmsCount", "contentId", "recipientsResults"}}
 * (https://promosms.com/api/rest/v3_2/sms). Request format as in Uptime Kuma's driver
 * (https://github.com/louislam/uptime-kuma/blob/master/server/notification-providers/promosms.js),
 * unverified: POST https://promosms.com/api/rest/v3_2/sms, HTTP Basic login + password, JSON {recipients:
 * [number], text, long-sms, type (0 flash, 1 eco, 3 full, 4 speed), sender, special-chars}; response.status
 * 0 = sent. Numbers sent international without "+" (unverified). The id returned is `contentId`.
 */
class PromoSmsDriver extends HttpSmsDriver
{
    public const API = 'https://promosms.com/api/rest/v3_2/sms';

    public static function fields(): array
    {
        return [
            'login' => ['required' => true],
            'password' => ['secret' => true, 'required' => true],
            'from' => [],
            'sms_type' => ['options' => ['3', '1', '4', '0']],
        ];
    }

    protected function provider(): string
    {
        return 'PromoSMS';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $payload = [
            'recipients' => [self::digits($phoneE164)],
            'text' => $text,
            'long-sms' => true,
            'special-chars' => ! self::isGsm7($text),
            'type' => (int) $channel->conf('sms_type', '3'),
            'sender' => (string) $channel->conf('from', ''),
        ];
        $response = $this->request($channel, fn ($http) => $http->asJson()->acceptJson()
            ->withBasicAuth($channel->conf('login'), $channel->conf('password'))->post(self::API, $payload));
        $this->failUnlessSuccessful($response, $response->json('response.status'));
        if ($response->json('response.status') !== 0) {
            throw $this->rejected($response->json('response.status'));
        }

        return self::id($response->json('response.contentId'));
    }
}
