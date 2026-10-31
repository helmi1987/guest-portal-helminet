<?php

namespace App\Federation;

use App\Approval\ApprovalService;
use App\Approval\ApprovalSettings;
use App\Models\ApprovalRequest;
use App\Models\Instance;
use App\Support\LocalInstance;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Approval decisions for requests of other instances (CONCEPT.md §10.5): approval requests stay at the
 * instance where the guest waits (origin). The Telegram owner forwards a button press to the origin,
 * directly or through the hub; the origin decides and updates its channel messages.
 */
class RemoteApprovals
{
    /** Outcomes: decided now, already decided/expired, unknown request. */
    public const STATUSES = ['done', 'closed', 'unknown'];

    public function __construct(
        private LocalInstance $instance,
        private Topology $topology,
        private PeerClient $client,
        private ApprovalService $approvals,
    ) {}

    /**
     * Decide a request at its origin instance.
     *
     * @param  string  $choice  duration key (e.g. 1d) or deny
     * @return string done | closed | unknown
     *
     * @throws RuntimeException origin unreachable or unknown
     */
    public function decide(string $origin, string $requestId, string $choice, string $by): string
    {
        if ($origin === $this->instance->id()) {
            return $this->decideLocal($requestId, $choice, $by);
        }
        $via = $this->topology->isDirectPeer($origin) ? Instance::query()->find($origin)
            : ($this->topology->isHub() ? null : $this->topology->hub());
        if ($via === null || ! $this->topology->isDirectPeer($via->id)) {
            throw new RuntimeException('no route to instance');
        }
        $response = $this->client->to($via, (int) config('portal.federation.live_timeout', 4))
            ->post('/federation/approvals/'.rawurlencode($requestId).'/decide', [
                'instance' => $origin, 'choice' => $choice, 'by' => mb_substr($by, 0, 255)]);
        $status = $response->json('status');
        if (! $response->successful() || ! in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('status '.$response->status().' '.mb_substr((string) $response->json('error'), 0, 100));
        }

        return $status;
    }

    /** Decision for a request waiting here (Telegram button pressed at the owner). */
    public function decideLocal(string $requestId, string $choice, string $by): string
    {
        $durations = ApprovalSettings::durations();
        $request = ApprovalRequest::query()->find($requestId);
        if ($request === null || $request->origin_instance_id !== $this->instance->id()
            || ($choice !== 'deny' && ! isset($durations[$choice]))) {
            return 'unknown';
        }
        $done = $this->approvals->decide($request, $choice === 'deny' ? null : $durations[$choice], $by, 'telegram');
        Log::info('Approval decided via forwarded Telegram press', ['request' => $requestId, 'status' => $done ? 'done' : 'closed']);

        return $done ? 'done' : 'closed';
    }
}
