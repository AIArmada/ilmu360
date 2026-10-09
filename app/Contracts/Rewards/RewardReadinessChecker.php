<?php

declare(strict_types=1);

namespace App\Contracts\Rewards;

use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\RewardReadinessAssessment;

/**
 * Readiness checks for the program lifecycle.
 *
 * Readiness (draft -> ready) is structural: a published version with valid
 * rules/terms exists. Activation (ready -> active) additionally requires the
 * fulfillment dependencies of the configured components. The null
 * implementation truthfully rejects money/benefit activation because no
 * funding, payee, retention, or reversal adapters exist in this slice; real
 * adapters arrive with the later finance scope. It never reports funded or
 * payee readiness.
 *
 * Caller assumption: invoked server-side by staff-driven program actions.
 * No member-reachable approval/activation endpoint exists in this slice.
 */
interface RewardReadinessChecker
{
    public function canMarkReady(RewardProgram $program, ?RewardProgramVersion $version): RewardReadinessAssessment;

    public function canActivate(RewardProgram $program, ?RewardProgramVersion $version): RewardReadinessAssessment;
}
