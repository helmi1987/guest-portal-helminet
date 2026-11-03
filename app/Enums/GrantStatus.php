<?php

namespace App\Enums;

enum GrantStatus: string
{
    case Pending = 'pending';     // valid_from in the future
    case Unused = 'unused';       // redeemable, not redeemed yet
    case Active = 'active';       // redeemed, time running
    case Lapsed = 'lapsed';       // never redeemed before redeem_until
    case Expired = 'expired';
    case Revoked = 'revoked';

    /** Statuses of the default codes list: the grant can still be (or is being) used. */
    public static function current(): array
    {
        return [self::Pending, self::Unused, self::Active];
    }

    /** Over for good (never redeemed in time, time over, revoked): such a grant may be deleted. */
    public function isEnded(): bool
    {
        return ! in_array($this, self::current(), true);
    }

    public function label(): string
    {
        return __('portal.grants.statuses.'.$this->value);
    }
}
