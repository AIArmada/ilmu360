<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardPreviewDecision: string
{
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
    case Hold = 'hold';
}
