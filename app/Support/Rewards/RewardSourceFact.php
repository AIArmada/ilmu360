<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardSourceCorrectionStatus;
use App\Enums\Rewards\RewardSourceFraudStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use Carbon\CarbonImmutable;

/**
 * A current source fact as observed through the source-reader boundary.
 *
 * Production adapters arrive after accepted share/claim integrations; until
 * then the only truthful readings are an explicit test-supplied fake (labeled
 * as fixture evidence in tests) or null (unknown source, always refused).
 */
final readonly class RewardSourceFact
{
    public function __construct(
        public string $sourceBusinessKey,
        public RewardEligibleOutcome $outcome,
        public ?string $category,
        public CarbonImmutable $occurredAt,
        public RewardVerificationLevel $verification,
        public bool $isFirstTime,
        public ?string $actorId,
        public ?string $sharerId,
        public RewardBeneficiaryRole $actorRole,
        public bool $isClaimedIdentity,
        public bool $isAttributed,
        public bool $isAmbiguousDevice = false,
        public bool $isBot = false,
        public bool $isCancelled = false,
        public bool $isValid = true,
        public RewardSourceFraudStatus $fraudStatus = RewardSourceFraudStatus::Clear,
        public RewardSourceCorrectionStatus $correctionStatus = RewardSourceCorrectionStatus::Current,
        public ?string $correctionId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'source_business_key' => $this->sourceBusinessKey,
            'outcome' => $this->outcome->value,
            'category' => $this->category,
            'occurred_at' => $this->occurredAt->setTimezone('UTC')->toIso8601String(),
            'verification' => $this->verification->value,
            'is_first_time' => $this->isFirstTime,
            'actor_id' => $this->actorId,
            'sharer_id' => $this->sharerId,
            'actor_role' => $this->actorRole->value,
            'is_claimed_identity' => $this->isClaimedIdentity,
            'is_attributed' => $this->isAttributed,
            'is_ambiguous_device' => $this->isAmbiguousDevice,
            'is_bot' => $this->isBot,
            'is_cancelled' => $this->isCancelled,
            'is_valid' => $this->isValid,
            'fraud_status' => $this->fraudStatus->value,
            'correction_status' => $this->correctionStatus->value,
            'correction_id' => $this->correctionId,
        ];
    }
}
