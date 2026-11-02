<?php

namespace App\Sms\Drivers;

use App\Models\Channel;
use App\Support\OutboundHttp;
use App\Support\Redact;
use RuntimeException;
use Throwable;

/**
 * Clickatell Platform REST API (CONCEPT.md §7.3). The endpoint is configurable (field api_url).
 * The API key is sent only in the Authorization header and is masked in every error message.
 *
 * Request format (to verify against a real account): POST {api_url} with header
 * "Authorization: <api key>" and JSON {"content": "...", "to": ["41790000000"], "from": "..."};
 * numbers in international format without "+", "from" optional (two-way number or sender id).
 * Response format (to verify): {"messages": [{"apiMessageId": "...", "accepted": true,
 * "to": "...", "errorCode": null, "error": null, "errorDescription": null}]}.
 */
class ClickatellDriver implements SmsDriver
{
    public const API = 'https://platform.clickatell.com/messages';

    public static function fields(): array
    {
        return [
            'api_key' => ['secret' => true, 'required' => true],
            'from' => [],
            'api_url' => [],
        ];
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $payload = ['content' => $text, 'to' => [ltrim($phoneE164, '+')]];
        if ($channel->conf('from')) {
            $payload['from'] = $channel->conf('from');
        }

        // Errors carry the HTTP status only: provider responses may echo the number or the request.
        try {
            $response = OutboundHttp::client(15)->asJson()->acceptJson()
                ->withHeaders(['Authorization' => $channel->conf('api_key')])
                ->post($channel->conf('api_url', self::API), $payload);
        } catch (Throwable $e) {
            throw new RuntimeException('Clickatell send failed: '.Redact::secrets($e->getMessage(), $channel->secretValues()));
        }
        if ($response->failed() || $response->redirect()) {
            throw new RuntimeException('Clickatell send failed: HTTP '.$response->status());
        }

        $message = $response->json('messages.0') ?? [];
        if (! ($message['accepted'] ?? false)) {
            throw new RuntimeException('Clickatell rejected the message: HTTP '.$response->status());
        }

        return $message['apiMessageId'] ?? null;
    }
}
