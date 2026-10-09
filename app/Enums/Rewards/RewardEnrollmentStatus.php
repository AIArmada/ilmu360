<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardEnrollmentStatus: string
{
    case Active = 'active';
    case Left = 'left';
}
