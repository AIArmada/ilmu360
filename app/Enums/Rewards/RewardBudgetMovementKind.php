<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardBudgetMovementKind: string
{
    case Reserve = 'reserve';
    case Release = 'release';
    case Spend = 'spend';
    case Recover = 'recover';
}
