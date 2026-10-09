<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Models\RewardAward;
use App\Models\RewardProgram;
use App\Models\RewardSourceComponentClaim;
use App\Models\RewardSourceCoordinator;

/**
 * Shared approval-chain acquisition for direct approvals and appeal
 * overturns.
 *
 * Both paths take source coordinator -> program -> claims BEFORE the award
 * row, so concurrent direct and overturn approvals for overlapping sources
 * can never form a lock cycle. The chain uses only the award's frozen
 * identity (source/component/role/program), which never changes between the
 * caller's unlocked pre-read and these acquisitions.
 */
final class RewardApprovalLocks
{
    public static function lockCoordinatorChain(RewardAward $pre): void
    {
        $coordinator = RewardSourceCoordinator::query()
            ->where('source_business_key', (string) $pre->source_business_key)
            ->where('component', $pre->component->value)
            ->where('beneficiary_role', $pre->beneficiary_role->value)
            ->lockForUpdate()
            ->first();

        if (! $coordinator instanceof RewardSourceCoordinator) {
            RewardSourceCoordinator::query()->create([
                'source_business_key' => (string) $pre->source_business_key,
                'component' => $pre->component,
                'beneficiary_role' => $pre->beneficiary_role,
            ]);
        }

        if ($pre->program_id !== null) {
            RewardProgram::query()->whereKey((string) $pre->program_id)->lockForUpdate()->first();
        }

        RewardSourceComponentClaim::query()
            ->where('source_business_key', (string) $pre->source_business_key)
            ->where('component', $pre->component->value)
            ->where('beneficiary_role', $pre->beneficiary_role->value)
            ->orderBy('family_key')
            ->lockForUpdate()
            ->get();
    }

    public static function lockAward(string $awardId): ?RewardAward
    {
        return RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();
    }
}
