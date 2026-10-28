<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's request for access, decided by an approver (CONCEPT.md §7.2 C).
 */
#[Fillable(['guest_id', 'site_id', 'mac', 'status', 'decided_by', 'decided_via', 'decided_at',
    'duration_s', 'grant_id', 'expires_at', 'meta'])]
class ApprovalRequest extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'duration_s' => 'integer',
            'meta' => 'array',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /** Status including lazy expiry of pending requests. */
    public function currentStatus(): ApprovalStatus
    {
        return $this->status === ApprovalStatus::Pending && $this->expires_at->isPast()
            ? ApprovalStatus::Expired
            : $this->status;
    }

    public function isPending(): bool
    {
        return $this->currentStatus() === ApprovalStatus::Pending;
    }

    public function guestName(): string
    {
        return trim(($this->guest?->first_name ?? '').' '.($this->guest?->last_name ?? ''));
    }
}
