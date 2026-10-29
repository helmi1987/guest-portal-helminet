<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's acceptance of a terms version at login (version null: no terms were published yet).
 */
#[Fillable(['guest_id', 'terms_version_id', 'grant_id', 'site_id', 'mac', 'accepted_at'])]
class TermsAcceptance extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(TermsVersion::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }
}
