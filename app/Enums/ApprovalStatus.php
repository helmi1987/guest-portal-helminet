<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';

    public function label(): string
    {
        return __('portal.approvals.statuses.'.$this->value);
    }
}
