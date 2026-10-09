<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardRecoveryKind: string
{
    case Receivable = 'receivable';
    case Adjustment = 'adjustment';
    case Dispute = 'dispute';
}
