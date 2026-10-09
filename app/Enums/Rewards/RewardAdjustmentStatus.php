<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardAdjustmentStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
