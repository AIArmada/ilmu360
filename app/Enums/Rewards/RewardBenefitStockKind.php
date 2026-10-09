<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

enum RewardBenefitStockKind: string
{
    case Reserve = 'reserve';
    case Release = 'release';
    case Consume = 'consume';
    case Restock = 'restock';
}
