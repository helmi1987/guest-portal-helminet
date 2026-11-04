<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * Octopush (API v1 "public"; CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://dev.octopush.com/en/sms-gateway-api-documentation/send-sms/
 *
 * POST https://api.octopush.com/v1/public/sms-campaign/send, headers api-key and api-login, JSON
 * {recipients: [{phone_number (international with "+")}], text, type, purpose: "alert", sender (optional,
 * 3-11 characters a-zA-Z0-9)}. Answer 201 with `sms_ticket`; errors 4xx with {code, message}. The `type`
 * values sms_premium / sms_low_cost appear in the docs' example and in Uptime Kuma's driver only
 * (unverified in the parameter table). Unicode handling is not documented (unverified).
 */
class OctopushDriver extends HttpSmsDriver
{
    public const API = 'https://api.octopush.com/v1/public/sms-campaign/send';

    public static function fields(): array
    {
        return [
            'login' => ['required' => true],
            'api_key' => ['secret' => true, 'required' => true],
            'from' => [],
            'sms_type' => ['options' => ['sms_premium', 'sms_low_cost']],
        ];
    }

    protected function provider(): string
    {
        return 'Octopush';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $payload = array_filter([
            'recipients' => [['phone_number' => $phoneE164]],
            'text' => $text,
            'type' => $channel->conf('sms_type', 'sms_premium'),
            'purpose' => 'alert',
            'sender' => $channel->conf('from'),
        ]);
        $response = $this->request($channel, fn ($http) => $http->asJson()->acceptJson()
            ->withHeaders(['api-key' => $channel->conf('api_key'), 'api-login' => $channel->conf('login')])
            ->post(self::API, $payload));
        $this->failUnlessSuccessful($response, $response->json('code'));

        return self::id($response->json('sms_ticket'));
    }
}
