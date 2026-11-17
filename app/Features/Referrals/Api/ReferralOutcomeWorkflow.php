<?php

declare(strict_types=1);

namespace App\Features\Referrals\Api;

interface ReferralOutcomeWorkflow
{
    public function qualify(string $businessKey): void;

    public function reverseRewards(string $businessKey, string $reason): void;
}
