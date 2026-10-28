<?php

namespace App\Approval;

use App\Enums\ApprovalStatus;
use App\Jobs\SendApprovalNotification;
use App\Models\ApprovalRequest;
use App\Models\Guest;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Services\GrantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Push approval lifecycle (CONCEPT.md §7.2 C): create, notify, decide.
 */
class ApprovalService
{
    public const TTL_MINUTES = 15;

    public function __construct(private GrantManager $grants) {}

    public function create(Guest $guest, Site $site, string $mac, ?string $ip): ApprovalRequest
    {
        $request = ApprovalRequest::create([
            'guest_id' => $guest->id,
            'site_id' => $site->id,
            'mac' => $mac,
            'ip' => $ip,
            'status' => ApprovalStatus::Pending,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
        Log::info('Approval requested', ['request' => $request->id, 'site' => $site->slug, 'mac' => $mac]);
        // Only the instance where the guest waits sends notifications (CONCEPT.md §10.5).
        SendApprovalNotification::dispatch($request->id, SendApprovalNotification::REQUESTED)->afterCommit();

        return $request;
    }

    /**
     * Approve (duration in seconds) or deny (null). Time of an approval runs from now.
     * Returns false when the request is no longer pending (decided elsewhere or expired).
     */
    public function decide(ApprovalRequest $request, ?int $durationS, string $by, string $via, ?User $user = null): bool
    {
        $result = DB::transaction(function () use ($request, $durationS, $by, $via, $user) {
            $fresh = ApprovalRequest::query()->lockForUpdate()->find($request->id);
            if ($fresh === null || $fresh->status !== ApprovalStatus::Pending) {
                return 'unchanged';
            }
            if ($fresh->expires_at->isPast()) {
                $fresh->update(['status' => ApprovalStatus::Expired]);
                $request->setRawAttributes($fresh->getAttributes(), true);

                return 'expired';
            }

            $attributes = ['decided_by' => mb_substr($by, 0, 255), 'decided_via' => $via, 'decided_at' => now()];
            if ($durationS === null) {
                $fresh->update($attributes + ['status' => ApprovalStatus::Denied]);
            } else {
                $grant = $this->grants->createApprovalGrant($fresh->site, $durationS, $fresh->guestName(), $user);
                $fresh->update($attributes + ['status' => ApprovalStatus::Approved, 'duration_s' => $durationS,
                    'grant_id' => $grant->id]);
                foreach (TermsAcceptance::query()->where('guest_id', $fresh->guest_id)->whereNull('grant_id')->get() as $acceptance) {
                    $acceptance->update(['grant_id' => $grant->id]);
                }
            }
            $request->setRawAttributes($fresh->getAttributes(), true);

            return 'decided';
        });

        if ($result === 'decided') {
            Log::info('Approval decided', ['request' => $request->id, 'status' => $request->status->value,
                'duration_s' => $request->duration_s, 'by' => $by, 'via' => $via]);
        }
        if ($result !== 'unchanged') {
            // Update the channel messages (e.g. remove the Telegram buttons).
            SendApprovalNotification::dispatch($request->id, SendApprovalNotification::DECIDED);
        }

        return $result === 'decided';
    }
}
