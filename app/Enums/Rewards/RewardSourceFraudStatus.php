<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardSourceFraudStatus: string
{
    case Clear = 'clear';
    case Suspect = 'suspect';
    case Confirmed = 'confirmed';
}
