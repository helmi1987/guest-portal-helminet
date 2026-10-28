<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';

    public function label(): string
    {
        return __('portal.roles.'.$this->value);
    }
}
