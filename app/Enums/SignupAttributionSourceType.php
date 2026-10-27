<?php

declare(strict_types=1);

namespace App\Enums;

enum SignupAttributionSourceType: string
{
    case Direct = 'direct';
    case ShareMember = 'share_member';
    case ShareGuest = 'share_guest';
    case CommercialAffiliate = 'commercial_affiliate';
    case ReferralInvite = 'referral_invite';
}
