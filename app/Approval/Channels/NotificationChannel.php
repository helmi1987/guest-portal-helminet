<?php

namespace App\Approval\Channels;

use App\Models\ApprovalRequest;
use App\Models\Channel;

/**
 * Notification channel driver (CONCEPT.md §7.3). Implementations throw on delivery errors.
 */
interface NotificationChannel
{
    /**
     * Config fields for the admin form: name => [secret => bool, required => bool].
     *
     * @return array<string, array{secret?: bool, required?: bool}>
     */
    public static function fields(): array;

    /** Announce a new request. Returns delivery data stored with the request (e.g. message id). */
    public function requested(ApprovalRequest $request, Channel $channel): ?array;

    /** Send a test message (admin "test" button); throws on delivery errors. */
    public function test(Channel $channel, string $text): void;

    /** The request was decided or expired; $meta is what requested() returned. */
    public function decided(ApprovalRequest $request, Channel $channel, ?array $meta): void;
}
