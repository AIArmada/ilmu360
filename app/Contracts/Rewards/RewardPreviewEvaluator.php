<?php

declare(strict_types=1);

namespace App\Contracts\Rewards;

use App\Support\Rewards\RewardPreviewContext;
use App\Support\Rewards\RewardPreviewResult;

/**
 * Pure deterministic policy computation over a point-in-time snapshot.
 * Implementations must not read the database, the clock, or request state.
 */
interface RewardPreviewEvaluator
{
    public function evaluate(RewardPreviewContext $context): RewardPreviewResult;
}
