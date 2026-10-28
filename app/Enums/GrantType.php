<?php

namespace App\Enums;

enum GrantType: string
{
    case Code = 'code';
    case Sms = 'sms';
    case Approval = 'approval';
}
