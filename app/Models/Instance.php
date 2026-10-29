<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Federation member (replicated, CONCEPT.md §10.2): federation URL, certificate pin (SPKI sha256) and
 * Ed25519 public key for event signatures.
 */
#[Fillable(['name', 'federation_url', 'admin_url', 'cert_fingerprint', 'signing_public_key'])]
class Instance extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'clock_skew_ms' => 'integer',
        ];
    }
}
