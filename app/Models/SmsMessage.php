<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sent SMS (local): basis for the cost caps and the delivery result (CONCEPT.md §7.2 B).
 */
#[Fillable(['otp_challenge_id', 'site_id', 'channel_id', 'mac', 'phone_e164', 'status', 'provider_id', 'error'])]
class SmsMessage extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const ACCEPTED = 'accepted';

    public const FAILED = 'failed';
}
