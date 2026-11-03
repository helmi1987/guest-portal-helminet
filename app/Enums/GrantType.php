<?php

namespace App\Enums;

enum GrantType: string
{
    case Code = 'code';
    case Sms = 'sms';
    case Approval = 'approval';
    /** Per-device grant created by a login with the site code (CONCEPT.md §8). */
    case SiteCode = 'site_code';
    /** Grant of a social login (Google, Apple, Facebook, GitHub), time from the login (CONCEPT.md §7.2 D). */
    case Social = 'social';
}
