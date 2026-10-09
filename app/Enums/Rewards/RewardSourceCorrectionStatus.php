<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardSourceCorrectionStatus: string
{
    case Current = 'current';
    case Corrected = 'corrected';
    case Withdrawn = 'withdrawn';
}
