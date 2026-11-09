<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

enum SignupAttributionSourceType: string
{
    case Direct = 'direct';
    case ShareMember = 'share_member';
    case ShareGuest = 'share_guest';
    case CommercialAffiliate = 'commercial_affiliate';
    case ReferralInvite = 'referral_invite';
}
