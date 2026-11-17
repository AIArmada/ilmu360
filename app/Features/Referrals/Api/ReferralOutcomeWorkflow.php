<?php

declare(strict_types=1);

namespace App\Features\Referrals\Api;

interface ReferralOutcomeWorkflow
{
    public function qualify(string $businessKey): void;

    public function reverseRewards(string $businessKey, string $reason): void;

    /** Queue a reversal after commit without coupling other features to the job. */
    public function scheduleRewardReversal(string $businessKey, string $reason): void;
}
