<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Rewards\Contracts\RewardGratitudePlanner;
use App\Features\Rewards\Api\GratitudeDigest;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Gratitude digests for reward achievements: plans delayed recipient
 * digests per achievement channel and cancels pending slots when the
 * achievement unwinds.
 *
 * Root adapter: converts the package's `CarbonImmutable` argument and
 * delegates to the Rewards feature Api.
 */
final readonly class DigestRewardGratitudePlanner implements RewardGratitudePlanner
{
    public function __construct(
        private GratitudeDigest $digests,
    ) {}

    public function planFor(
        string $recipientType,
        string $recipientId,
        array $channels,
        CarbonImmutable $at,
    ): void {
        $this->digests->plan(
            $recipientType,
            $recipientId,
            $channels,
            DateTimeImmutable::createFromInterface($at),
        );
    }

    public function cancelPendingFor(
        string $recipientType,
        string $recipientId,
        string $reason,
        CarbonImmutable $at,
    ): void {
        $this->digests->cancelPendingForRecipient(
            $recipientType,
            $recipientId,
            $reason,
            DateTimeImmutable::createFromInterface($at),
        );
    }
}
