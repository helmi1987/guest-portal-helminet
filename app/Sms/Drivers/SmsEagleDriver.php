<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * SMSEagle hardware/virtual gateway, API v2 (CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://www.smseagle.eu/docs/apiv2/
 *
 * POST {device URL}/api/v2/messages/sms, header access-token, JSON {to: [number], text, encoding
 * (standard / unicode), modem_no (optional)}. Answer: array of {message_id, status: "queued", number};
 * errors by HTTP status (codes not listed in the docs). The number is sent in E.164 with "+" (unverified
 * whether the gateway's modem needs a national format; it accepts what the SIM's network accepts).
 * TLS is always verified (no toggle, like the other drivers): a device with a self-signed certificate
 * needs a proper certificate or a reverse proxy.
 */
class SmsEagleDriver extends HttpSmsDriver
{
    public static function fields(): array
    {
        return [
            'url' => ['required' => true],
            'access_token' => ['secret' => true, 'required' => true],
            'device_id' => [],
        ];
    }

    protected function provider(): string
    {
        return 'SMSEagle';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $payload = ['to' => [$phoneE164], 'text' => $text, 'encoding' => self::isGsm7($text) ? 'standard' : 'unicode'];
        if ($channel->conf('device_id') !== null) {
            $payload['modem_no'] = (int) $channel->conf('device_id');
        }
        $url = rtrim((string) $channel->conf('url'), '/').'/api/v2/messages/sms';
        $response = $this->request($channel, fn ($http) => $http->asJson()->acceptJson()
            ->withHeaders(['access-token' => $channel->conf('access_token')])->post($url, $payload));
        $this->failUnlessSuccessful($response);

        $message = $response->json('0') ?? [];
        if (($message['status'] ?? null) !== 'queued') {
            throw $this->rejected(is_scalar($message['status'] ?? null) ? $message['status'] : null);
        }

        return self::id($message['message_id'] ?? null);
    }
}
