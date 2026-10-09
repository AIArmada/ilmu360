<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Contracts\Rewards\RewardSourceFactReader;

/**
 * Default source reader: unknown for every key.
 *
 * Refusing unknown sources is the only truthful behavior before accepted
 * share/claim adapters exist. Never synthesize source proof here.
 */
final class NullRewardSourceFactReader implements RewardSourceFactReader
{
    public function read(string $sourceBusinessKey): ?RewardSourceFact
    {
        return null;
    }
}
