<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardMilestoneStatus: string
{
    case Open = 'open';
    case Achieved = 'achieved';
    case Invalidated = 'invalidated';
}
