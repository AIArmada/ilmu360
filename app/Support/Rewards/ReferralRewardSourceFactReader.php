<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Referrals\Enums\ReferralInviteStatus;
use AIArmada\Referrals\Enums\ReferralRewardSide;
use AIArmada\Referrals\Enums\ReferralRewardStatus;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Referrals\Models\ReferralReward;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardSourceCorrectionStatus;
use AIArmada\Rewards\Enums\RewardSourceFraudStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Support\RewardSourceFact;
use App\Enums\DawahShareOutcomeType;
use App\Models\ShareOutcomeCorrection;
use App\Models\User;
use App\Support\ShareTracking\ActivityRewardBusinessKey;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Reads referral fulfilment facts from the package lifecycle and its source transition. */
final readonly class ReferralRewardSourceFactReader implements RewardSourceFactReader
{
    public function __construct(private ActivityRewardSourceFactReader $activityReader) {}

    public function read(string $sourceBusinessKey): ?RewardSourceFact
    {
        $parsed = $this->parse($sourceBusinessKey);

        if ($parsed === null) {
            return null;
        }

        [$inviteId, $side] = $parsed;
        $reward = ReferralReward::query()
            ->where('invite_id', $inviteId)
            ->where('side', $side->value)
            ->first();

        if (! $reward instanceof ReferralReward) {
            return null;
        }

        $invite = $reward->invite;
        $program = $invite?->program;
        $acceptedBy = $invite?->acceptedBy;
        $occurredAt = $invite?->qualified_at;
        $qualificationKey = $invite?->qualification_business_key;

        if (! $invite instanceof ReferralInvite
            || $program === null
            || ! $acceptedBy instanceof User
            || ! is_string($qualificationKey)
            || $qualificationKey === ''
            || ! $occurredAt instanceof CarbonImmutable) {
            return null;
        }

        $source = $this->readQualifyingSource($qualificationKey);
        $correction = ShareOutcomeCorrection::query()->where('business_key', $qualificationKey)->first();
        $beneficiaryId = $side === ReferralRewardSide::Referrer
            ? $this->userId($invite->referrer_type, $invite->referrer_id)
            : $this->userId($invite->accepted_by_type, $invite->accepted_by_id);
        $expectedBeneficiaryId = $side === ReferralRewardSide::Referrer
            ? $beneficiaryId
            : (string) $acceptedBy->getKey();
        $acceptedByMatchesSource = $source !== null
            && $source->actorId === (string) $acceptedBy->getKey()
            && $source->isClaimedIdentity;
        $sourceTimeMatchesQualification = $source !== null
            && $source->occurredAt->equalTo($occurredAt);
        $sourceMeetsQualification = $source !== null
            && $source->isValid
            && ! $source->isCancelled
            && $source->isClaimedIdentity
            && match ($source->outcome) {
                DawahShareOutcomeType::EventRegistration->value => $source->verification === RewardVerificationLevel::Accepted,
                DawahShareOutcomeType::EventCheckin->value => $source->verification === RewardVerificationLevel::StaffVerified,
                default => false,
            };
        $sourceIsCancelled = $source === null || $source->isCancelled;
        $sourceVerification = $source === null ? RewardVerificationLevel::Unverified : $source->verification;
        $sourceIsAmbiguousDevice = $source !== null && $source->isAmbiguousDevice;
        $sourceIsBot = $source !== null && $source->isBot;
        $sourceFraudStatus = $source === null ? RewardSourceFraudStatus::Clear : $source->fraudStatus;
        $sourceCorrectionStatus = $source === null ? RewardSourceCorrectionStatus::Current : $source->correctionStatus;
        $sourceCorrectionId = $source === null ? null : $source->correctionId;
        $inviteStatusValid = in_array($invite->status, [ReferralInviteStatus::Qualified, ReferralInviteStatus::Rewarded], true);
        $rewardStatusValid = $reward->status !== ReferralRewardStatus::Reversed;
        $isValid = $inviteStatusValid
            && $rewardStatusValid
            && $beneficiaryId !== null
            && $expectedBeneficiaryId !== null
            && (string) $reward->beneficiary_id === $expectedBeneficiaryId
            && $acceptedByMatchesSource
            && $sourceTimeMatchesQualification
            && $sourceMeetsQualification
            && $correction === null;
        $isCancelled = ! $isValid || $sourceIsCancelled;
        $correctionStatus = $correction instanceof ShareOutcomeCorrection || ! $isValid
            ? RewardSourceCorrectionStatus::Withdrawn
            : $sourceCorrectionStatus;
        $referrerId = $this->userId($invite->referrer_type, $invite->referrer_id);

        return new RewardSourceFact(
            sourceBusinessKey: $sourceBusinessKey,
            outcome: 'referral_qualified',
            category: is_string($program->slug) ? $program->slug : null,
            occurredAt: $occurredAt->setTimezone('UTC'),
            verification: $sourceVerification,
            isFirstTime: true,
            actorId: (string) $acceptedBy->getKey(),
            sharerId: $referrerId,
            actorRole: RewardBeneficiaryRole::Participant,
            isClaimedIdentity: $acceptedByMatchesSource,
            isAttributed: $inviteStatusValid && $referrerId !== null,
            isAmbiguousDevice: $sourceIsAmbiguousDevice,
            isBot: $sourceIsBot,
            isCancelled: $isCancelled,
            isValid: $isValid,
            fraudStatus: $sourceFraudStatus,
            correctionStatus: $correctionStatus,
            correctionId: $correction instanceof ShareOutcomeCorrection ? (string) $correction->getKey() : $sourceCorrectionId,
        );
    }

    /**
     * @return array{string, ReferralRewardSide}|null
     */
    private function parse(string $businessKey): ?array
    {
        if (mb_strlen($businessKey) > 191 || str_contains($businessKey, "\0")) {
            return null;
        }

        if (preg_match('/^referrals:qualified:([0-9a-fA-F-]{36}):(referrer|referee)$/D', $businessKey, $matches) !== 1
            || ! Str::isUuid($matches[1])) {
            return null;
        }

        $side = ReferralRewardSide::tryFrom($matches[2]);

        return $side instanceof ReferralRewardSide ? [$matches[1], $side] : null;
    }

    private function readQualifyingSource(string $businessKey): ?RewardSourceFact
    {
        foreach ([DawahShareOutcomeType::EventRegistration, DawahShareOutcomeType::EventCheckin] as $outcome) {
            try {
                $parsed = ShareOutcomeBusinessKey::parse($businessKey, $outcome);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $activityKey = ActivityRewardBusinessKey::for($outcome, $parsed['source_transition_id']);

            return $this->activityReader->read($activityKey);
        }

        return null;
    }

    private function userId(?string $type, ?string $id): ?string
    {
        if ($type === null || $id === null || $id === '') {
            return null;
        }

        $user = (new User)->getMorphClass();

        return $type === $user ? $id : null;
    }
}
