<?php

namespace App\Enums;

enum ExpiryMode: string
{
    /** Time runs from the first redemption. */
    case FirstLogin = 'first_login';
    /** Time runs from creation. */
    case Creation = 'creation';

    public function label(): string
    {
        return __('portal.grants.expiry_modes.'.$this->value);
    }
}
