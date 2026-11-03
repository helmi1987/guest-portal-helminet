<?php

namespace App\Sms\Drivers;

use App\Approval\Channels\WebhookChannel;
use App\Models\Channel;
use App\Support\OutboundHttp;
use App\Support\Redact;
use RuntimeException;
use Throwable;

/**
 * Generic SMS webhook: signed JSON POST {event, to, text} for an own SMS gateway. Same signature
 * headers as the notification webhook (X-Timestamp, X-Signature).
 */
class WebhookSmsDriver implements SmsDriver
{
    public static function fields(): array
    {
        return [
            'url' => ['required' => true],
            'secret' => ['secret' => true, 'required' => true],
        ];
    }

    public function send(Channel $channel, string $phoneE164, string $text): ?string
    {
        $body = json_encode(['event' => 'sms.send', 'to' => $phoneE164, 'text' => $text],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        // Errors carry the HTTP status only: the gateway response may echo the number or the request.
        try {
            $response = OutboundHttp::client(15)
                ->withHeaders([
                    'X-Timestamp' => $timestamp,
                    'X-Signature' => WebhookChannel::signature($channel->conf('secret'), $timestamp, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($channel->conf('url'));
        } catch (Throwable $e) {
            throw new RuntimeException('SMS webhook failed: '.Redact::secrets($e->getMessage(), $channel->secretValues()));
        }
        if ($response->failed() || $response->redirect()) {
            throw new RuntimeException('SMS webhook failed: HTTP '.$response->status());
        }

        $id = $response->json('id');

        return is_scalar($id) ? (string) $id : null;
    }
}
