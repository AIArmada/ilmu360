<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardPayeeStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
