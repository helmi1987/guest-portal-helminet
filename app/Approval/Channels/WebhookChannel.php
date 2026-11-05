<?php

namespace App\Approval\Channels;

use App\Approval\ApprovalLinks;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Support\OutboundHttp;

/**
 * Generic webhook: JSON POST signed with HMAC-SHA256 over "<timestamp>.<body>"
 * (headers X-Timestamp, X-Signature: sha256=<hex>); receivers should reject old timestamps. Events: approval.requested,
 * approval.decided, test, router.outdated and router.unreachable (announce(), decision 2026-11-05, v0.30.0).
 */
class WebhookChannel implements NotificationChannel
{
    public static function fields(): array
    {
        return [
            'url' => ['required' => true],
            'secret' => ['secret' => true, 'required' => true],
        ];
    }

    public function requested(ApprovalRequest $request, Channel $channel): ?array
    {
        $this->post($channel, $this->payload('approval.requested', $request) + ['links' => ApprovalLinks::for($request)]);

        return null;
    }

    public function decided(ApprovalRequest $request, Channel $channel, ?array $meta): void
    {
        $this->post($channel, $this->payload('approval.decided', $request) + [
            'status' => $request->currentStatus()->value,
            'duration_s' => $request->duration_s,
            'decided_by' => $request->decided_by,
            'decided_at' => $request->decided_at?->toIso8601String(),
        ]);
    }

    public function test(Channel $channel, string $text): void
    {
        $this->post($channel, ['event' => 'test', 'message' => $text, 'sent_at' => now()->toIso8601String()]);
    }

    /** $data as the body (its "event" names it) plus title, message and the sending time. */
    public function announce(Channel $channel, string $title, string $text, array $data): void
    {
        $this->post($channel, $data + ['title' => $title, 'message' => $text, 'sent_at' => now()->toIso8601String()]);
    }

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function payload(string $event, ApprovalRequest $request): array
    {
        return [
            'event' => $event,
            'id' => $request->id,
            'guest' => ['first_name' => $request->guest?->first_name, 'last_name' => $request->guest?->last_name,
                'display_name' => $request->guestName()],
            'site' => ['slug' => $request->site?->slug, 'name' => $request->site?->name],
            'mac' => $request->mac,
            'created_at' => $request->created_at?->toIso8601String(),
            'expires_at' => $request->expires_at->toIso8601String(),
        ];
    }

    private function post(Channel $channel, array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();

        OutboundHttp::rejectRedirect(OutboundHttp::client(10)
            ->withHeaders([
                'X-Timestamp' => $timestamp,
                'X-Signature' => self::signature($channel->conf('secret'), $timestamp, $body),
            ])
            ->withBody($body, 'application/json')
            ->post($channel->conf('url'))
            ->throw());
    }
}
