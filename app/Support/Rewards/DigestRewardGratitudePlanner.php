<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Rewards\Contracts\RewardGratitudePlanner;
use App\Actions\Rewards\CancelRewardDigestAction;
use App\Actions\Rewards\PlanRewardGratitudeDigestAction;
use Carbon\CarbonImmutable;

/**
 * Gratitude digests for reward achievements: plans delayed recipient
 * digests per achievement channel and cancels pending slots when the
 * achievement unwinds.
 */
final class DigestRewardGratitudePlanner implements RewardGratitudePlanner
{
    public function planFor(
        string $recipientType,
        string $recipientId,
        array $channels,
        CarbonImmutable $at,
    ): void {
        PlanRewardGratitudeDigestAction::run($recipientType, $recipientId, $channels, $at);
    }

    public function cancelPendingFor(
        string $recipientType,
        string $recipientId,
        string $reason,
        CarbonImmutable $at,
    ): void {
        CancelRewardDigestAction::cancelPendingForRecipient($recipientType, $recipientId, $reason, $at);
    }
}
