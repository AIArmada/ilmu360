<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardAdjustmentKind: string
{
    case Override = 'override';
    case Adjustment = 'adjustment';
    case Dispute = 'dispute';
}
