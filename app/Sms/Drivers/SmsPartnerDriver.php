<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * SMS Partner (smspartner.fr; CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://www.docpartner.dev/api/sms-partner/envoyer-des-sms/envoi-unitaire.md
 *
 * POST https://api.smspartner.fr/v1/send, JSON {apiKey, phoneNumbers (international with "+"), message,
 * sender (optional, 3-11 characters without special characters)}. `isUnicode` must be enabled on the
 * account by SMS Partner and is not sent. Success {success: true, code: 200, message_id}; error {success:
 * false, code, errors}. Code 2 (number missing) rejects the number; 9 (a constraint, e.g. invalid number or
 * sender too long) is ambiguous and counts as provider failure; 1/10 (API key), 11 (credit) as well.
 */
class SmsPartnerDriver extends HttpSmsDriver
{
    public const API = 'https://api.smspartner.fr/v1/send';

    public static function fields(): array
    {
        return [
            'api_key' => ['secret' => true, 'required' => true],
            'from' => [],
        ];
    }

    protected function provider(): string
    {
        return 'SMS Partner';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $payload = array_filter(['apiKey' => $channel->conf('api_key'), 'phoneNumbers' => $phoneE164, 'message' => $text,
            'sender' => $channel->conf('from')]);
        $response = $this->request($channel, fn ($http) => $http->asJson()->acceptJson()->post(self::API, $payload));
        $this->failUnlessSuccessful($response, $response->json('code'), [2]);
        if ($response->json('success') !== true) {
            throw $this->rejected($response->json('code'), [2]);
        }

        return self::id($response->json('message_id'));
    }
}
