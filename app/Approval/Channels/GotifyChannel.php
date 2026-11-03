<?php

namespace App\Approval\Channels;

use App\Approval\ApprovalLinks;
use App\Approval\ApprovalMessage;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Support\OutboundHttp;

/**
 * Gotify message with signed approve/deny links; a click opens the confirmation page.
 */
class GotifyChannel implements NotificationChannel
{
    public static function fields(): array
    {
        return [
            'url' => ['required' => true],
            'app_token' => ['secret' => true, 'required' => true],
            'priority' => [],
        ];
    }

    public function requested(ApprovalRequest $request, Channel $channel): ?array
    {
        $links = ApprovalLinks::for($request);
        // Guest input must not inject Markdown (e.g. links).
        $lines = array_map(fn (string $line) => addcslashes($line, '\\`*_{}[]()#+-.!|<>'), ApprovalMessage::lines($request));
        foreach ($links as $choice => $url) {
            if ($choice !== 'confirm') {
                $lines[] = '['.ApprovalMessage::choiceLabel($choice).']('.$url.')';
            }
        }

        OutboundHttp::rejectRedirect(OutboundHttp::client(10)->asJson()
            ->withHeaders(['X-Gotify-Key' => $channel->conf('app_token')])
            ->post(rtrim($channel->conf('url'), '/').'/message', [
                'title' => ApprovalMessage::title(),
                'message' => implode("  \n", $lines),
                'priority' => (int) $channel->conf('priority', 8),
                'extras' => [
                    'client::display' => ['contentType' => 'text/markdown'],
                    'client::notification' => ['click' => ['url' => $links['confirm']]],
                ],
            ])->throw());

        return null;
    }

    public function test(Channel $channel, string $text): void
    {
        OutboundHttp::rejectRedirect(OutboundHttp::client(10)->asJson()
            ->withHeaders(['X-Gotify-Key' => $channel->conf('app_token')])
            ->post(rtrim($channel->conf('url'), '/').'/message', [
                'title' => ApprovalMessage::title(),
                'message' => $text,
                'priority' => (int) $channel->conf('priority', 8),
            ])->throw());
    }

    /** Gotify messages cannot be edited. */
    public function decided(ApprovalRequest $request, Channel $channel, ?array $meta): void {}
}
