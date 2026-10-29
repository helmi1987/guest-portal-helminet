<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail of admin/manager actions, logins and approval decisions (CONCEPT.md §9, §11).
 * Write through App\Support\Audit, which strips secrets from `data`.
 */
#[Fillable(['user_id', 'actor', 'action', 'subject_type', 'subject_id', 'data', 'ip'])]
class AuditLog extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Action group, e.g. "grant" for "grant.extend". */
    public function group(): string
    {
        return strtok($this->action, '.');
    }
}
