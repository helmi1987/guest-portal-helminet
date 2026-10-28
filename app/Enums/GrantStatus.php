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

    public function label(): string
    {
        return __('portal.grants.statuses.'.$this->value);
    }
}
