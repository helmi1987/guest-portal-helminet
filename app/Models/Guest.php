<?php

namespace App\Models;

use App\Auth\Social\SocialLogin;
use App\Enums\GrantType;
use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A guest as entered at one login (name, optional phone; for a social login the provider, its subject
 * and the email it reported, the name as far as the provider reports it, else empty). Each login
 * creates a new row.
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

    /**
     * Name as shown and passed on (admin lists, approval messages, webhook `display_name`): "First Last",
     * or "Last, First" with $lastFirst. Without a name (social login whose provider reported none, decision
     * 2026-11-05) the email, else "<Provider> #<first 6 hex of a hash of provider and subject>" (never the
     * raw provider ID). $email formats the email (e.g. User::displayEmail, masked for managers).
     *
     * @param  (callable(string): string)|null  $email
     */
    public function displayName(bool $lastFirst = false, ?callable $email = null): string
    {
        $first = trim((string) $this->first_name);
        $last = trim((string) $this->last_name);
        if ($first !== '' && $last !== '') {
            return $lastFirst ? $last.', '.$first : $first.' '.$last;
        }
        if ($first !== '' || $last !== '') {
            return $first.$last;
        }
        if (is_string($this->email) && $this->email !== '') {
            return $email !== null ? $email($this->email) : $this->email;
        }
        if ($this->social_provider !== null) {
            return SocialLogin::name($this->social_provider).' #'.substr(hash('sha256', $this->social_provider.'|'.$this->social_subject), 0, 6);
        }

        return '–';
    }
}
