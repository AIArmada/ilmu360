<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Support\RewardSourceFact;

/** Routes canonical reward source keys to their single namespace reader. */
final class CompositeRewardSourceFactReader implements RewardSourceFactReader
{
    public function __construct(
        private readonly ActivityRewardSourceFactReader $activityReader,
        private readonly ShareAttributionRewardSourceFactReader $shareReader,
        private readonly ReferralRewardSourceFactReader $referralReader,
    ) {}

    public function read(string $sourceBusinessKey): ?RewardSourceFact
    {
        if (str_starts_with($sourceBusinessKey, 'activity:')) {
            return $this->activityReader->read($sourceBusinessKey);
        }

        if (str_starts_with($sourceBusinessKey, 'share-outcome:')) {
            return $this->shareReader->read($sourceBusinessKey);
        }

        if (str_starts_with($sourceBusinessKey, 'referrals:')) {
            return $this->referralReader->read($sourceBusinessKey);
        }

        return null;
    }
}
