<?php

declare(strict_types=1);

namespace App\Features\Referrals\Services;

use App\Features\Referrals\Actions\QualifyReferralFromShareOutcomeAction;
use App\Features\Referrals\Actions\ReverseReferralRewardsForShareOutcomeAction;
use App\Features\Referrals\Api\ReferralOutcomeWorkflow;
use App\Features\Referrals\EntryPoints\Jobs\ReverseReferralRewardsForShareOutcomeJob;

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

    public function scheduleRewardReversal(string $businessKey, string $reason): void
    {
        ReverseReferralRewardsForShareOutcomeJob::dispatch($businessKey, $reason)->afterCommit();
    }
}
