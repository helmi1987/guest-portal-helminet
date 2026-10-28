<?php

namespace App\Jobs;

use App\Models\ApprovalRequest;
use App\Models\Channel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notify all enabled channels about a new request or about its decision. A failing channel does
 * not block the others; failures are logged, not retried (the request expires after 15 min).
 */
class SendApprovalNotification implements ShouldQueue
{
    use Queueable;

    public const REQUESTED = 'requested';

    public const DECIDED = 'decided';

    public int $tries = 1;

    public function __construct(public string $requestId, public string $event) {}

    public function handle(): void
    {
        $request = ApprovalRequest::query()->with(['guest', 'site'])->find($this->requestId);
        if ($request === null) {
            return;
        }

        $meta = $request->meta ?? [];
        foreach (Channel::query()->activeNotification()->get() as $channel) {
            try {
                if ($this->event === self::REQUESTED) {
                    $result = $channel->driver()->requested($request, $channel);
                    if ($result !== null) {
                        $meta[$channel->id] = $result;
                    }
                } else {
                    $channel->driver()->decided($request, $channel, $meta[$channel->id] ?? null);
                }
            } catch (Throwable $e) {
                Log::warning('Approval notification failed', ['request' => $request->id, 'channel' => $channel->id,
                    'driver' => $channel->driver, 'event' => $this->event, 'error' => $e->getMessage()]);
            }
        }

        if ($this->event === self::REQUESTED && $meta !== ($request->meta ?? [])) {
            // Plain update without touching other columns decided meanwhile.
            ApprovalRequest::query()->whereKey($request->id)->update(['meta' => json_encode($meta)]);
        }
    }
}
