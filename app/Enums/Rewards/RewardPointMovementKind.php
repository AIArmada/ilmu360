<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardPointMovementKind: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Reserve = 'reserve';
    case Release = 'release';
    case Expire = 'expire';
    case Refund = 'refund';
    case Adjust = 'adjust';
}
