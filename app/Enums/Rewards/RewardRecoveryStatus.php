<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardRecoveryStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
    case WrittenOff = 'written_off';
}
