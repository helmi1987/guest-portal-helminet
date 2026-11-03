<?php

namespace App\Models;

use App\Enums\GrantType;
use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A guest as entered at one login (name, optional phone; for a social login the provider, its subject
 * and the email it reported). Each login creates a new row.
 */
#[Fillable(['first_name', 'last_name', 'phone_e164', 'locale', 'social_provider', 'social_subject', 'email'])]
class Guest extends Model
{
    use Replicated;

    public function acceptances(): HasMany
    {
        return $this->hasMany(TermsAcceptance::class);
    }

    public function latestAcceptance(): HasOne
    {
        return $this->hasOne(TermsAcceptance::class)->latestOfMany('accepted_at');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function otpChallenges(): HasMany
    {
        return $this->hasMany(OtpChallenge::class);
    }

    /**
     * Login method: the grant type, or for a guest without grant (pending, denied, unverified) the
     * started flow. Needs the `approval_requests_exists` / `otp_challenges_exists` attributes
     * (withExists) when no grant is given.
     */
    public function loginMethod(?Grant $grant): ?GrantType
    {
        return $grant?->type ?? match (true) {
            (bool) $this->approval_requests_exists => GrantType::Approval,
            (bool) $this->otp_challenges_exists => GrantType::Sms,
            $this->social_provider !== null => GrantType::Social,
            default => null,
        };
    }
}
