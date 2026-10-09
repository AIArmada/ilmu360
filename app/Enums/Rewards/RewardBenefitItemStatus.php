<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardBenefitItemStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
