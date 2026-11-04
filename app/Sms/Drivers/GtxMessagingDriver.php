<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * GTX Messaging (CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://www.gtx-messaging.com/en/api-docs/sms-rest-api/
 *
 * POST https://rest.gtx-messaging.net/smsc/sendsms/{auth key}/json (the key is part of the path and masked
 * in errors), form fields from (11 alphanumeric characters or a number), to (international with "+"), text;
 * non-GSM text with coding=2 (UCS-2) and charset=utf-8. Answer 200 {message-count, message-status,
 * message-id}; 400 invalid input, 401/403 not authorised, 500 server error (only 500 is retriable).
 * Whether message-id is a list for multi-part messages is not shown (both handled).
 */
class GtxMessagingDriver extends HttpSmsDriver
{
    public const API = 'https://rest.gtx-messaging.net/smsc/sendsms/';

    public static function fields(): array
    {
        return [
            'api_key' => ['secret' => true, 'required' => true],
            'sender' => ['required' => true],
        ];
    }

    protected function provider(): string
    {
        return 'GTX Messaging';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $form = ['from' => $channel->conf('sender'), 'to' => $phoneE164, 'text' => $text];
        if (! self::isGsm7($text)) {
            $form += ['coding' => 2, 'charset' => 'utf-8'];
        }
        $url = self::API.rawurlencode((string) $channel->conf('api_key')).'/json';
        $response = $this->request($channel, fn ($http) => $http->asForm()->acceptJson()->post($url, $form));
        $this->failUnlessSuccessful($response);

        $id = $response->json('message-id');

        return self::id(is_array($id) ? ($id[0] ?? null) : $id);
    }
}
