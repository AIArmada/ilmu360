<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardEvaluationOutcome: string
{
    case Awarded = 'awarded';
    case Held = 'held';
    case Rejected = 'rejected';
}
