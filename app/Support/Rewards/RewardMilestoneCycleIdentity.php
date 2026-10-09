<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Deterministic milestone-cycle identity.
 *
 * A cycle is (recipient, family, milestone_key, window_start) where
 * window_start is the fixed UTC window containing the contribution's
 * occurrence time. Fixed windows make the identity computable from any
 * single contribution without reading prior state.
 */
final class RewardMilestoneCycleIdentity
{
    public static function windowStart(CarbonImmutable $occurredAt, int $windowDays): CarbonImmutable
    {
        if ($windowDays < 1 || $windowDays > 365) {
            throw new InvalidArgumentException('Milestone window_days must be 1..365.');
        }

        $occurredAt = $occurredAt->setTimezone('UTC')->startOfDay();
        $epochDays = intdiv($occurredAt->getTimestamp(), 86400);
        $windowIndex = intdiv($epochDays, $windowDays);

        return CarbonImmutable::createFromTimestampUTC($windowIndex * $windowDays * 86400)->startOfDay();
    }

    public static function assertDefinition(string $milestoneKey, int $targetCount, int $windowDays): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $milestoneKey) !== 1) {
            throw new InvalidArgumentException('Milestone key must be a 1..64 slug.');
        }

        if ($targetCount < 1 || $targetCount > 1000000) {
            throw new InvalidArgumentException('Milestone target_count must be 1..1000000.');
        }

        if ($windowDays < 1 || $windowDays > 365) {
            throw new InvalidArgumentException('Milestone window_days must be 1..365.');
        }
    }

    public static function milestoneSourceKey(string $familyKey, string $milestoneKey, CarbonImmutable $windowStart): string
    {
        return 'milestone:'.$familyKey.':'.$milestoneKey.':'.$windowStart->setTimezone('UTC')->format('Y-m-d');
    }
}
