<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardBadgeStatus: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';
}
