<?php

declare(strict_types=1);

namespace App\Features\Referrals\Services;

use App\Features\Referrals\Actions\QualifyReferralFromShareOutcomeAction;
use App\Features\Referrals\Actions\ReverseReferralRewardsForShareOutcomeAction;
use App\Features\Referrals\Api\ReferralOutcomeWorkflow;

final readonly class ReferralOutcomeService implements ReferralOutcomeWorkflow
{
    public function __construct(
        private QualifyReferralFromShareOutcomeAction $qualification,
        private ReverseReferralRewardsForShareOutcomeAction $reversal,
    ) {}

    public function qualify(string $businessKey): void
    {
        $this->qualification->handle($businessKey);
    }

    public function reverseRewards(string $businessKey, string $reason): void
    {
        $this->reversal->handle($businessKey, $reason);
    }
}
