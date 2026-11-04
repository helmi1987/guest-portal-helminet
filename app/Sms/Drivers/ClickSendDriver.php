<?php

namespace App\Sms\Drivers;

use App\Models\Channel;

/**
 * ClickSend (CONCEPT.md §7.3), verified against docs on 2026-11-04:
 * https://developers.clicksend.com/docs/messaging/sms/other/send-sms
 *
 * POST https://rest.clicksend.com/v3/sms/send, HTTP Basic with username + API key, JSON
 * {messages: [{to (E.164 with "+"), body, from (optional), source}]}. The answer's `http_code` does not
 * reflect each message: data.messages[0].status must be "SUCCESS", the id is message_id. The status value
 * INVALID_RECIPIENT (rejects the number) is taken from ClickSend's status code list and not shown on the
 * page above (unverified).
 */
class ClickSendDriver extends HttpSmsDriver
{
    public const API = 'https://rest.clicksend.com/v3/sms/send';

    public static function fields(): array
    {
        return [
            'username' => ['required' => true],
            'api_key' => ['secret' => true, 'required' => true],
            'from' => [],
        ];
    }

    protected function provider(): string
    {
        return 'ClickSend';
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $message = array_filter(['to' => $phoneE164, 'body' => $text, 'from' => $channel->conf('from'), 'source' => 'guest-portal']);
        $response = $this->request($channel, fn ($http) => $http->asJson()->acceptJson()
            ->withBasicAuth($channel->conf('username'), $channel->conf('api_key'))
            ->post(self::API, ['messages' => [$message]]));
        $this->failUnlessSuccessful($response, $response->json('response_code'));

        $status = $response->json('data.messages.0.status');
        if ($status !== 'SUCCESS') {
            throw $this->rejected($status, ['INVALID_RECIPIENT']);
        }

        return self::id($response->json('data.messages.0.message_id'));
    }
}
