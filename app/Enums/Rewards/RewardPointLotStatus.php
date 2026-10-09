<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardPointLotStatus: string
{
    case Open = 'open';
    case Depleted = 'depleted';
    case Expired = 'expired';
}
