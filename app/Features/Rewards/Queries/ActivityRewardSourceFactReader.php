<?php

declare(strict_types=1);

namespace App\Features\Rewards\Queries;

use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardSourceCorrectionStatus;
use AIArmada\Rewards\Enums\RewardSourceFraudStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Support\RewardSourceFact;
use App\Features\Engagement\Api\EngagementActivity;
use App\Features\Engagement\Api\EngagementActivityReader;
use App\Features\Events\Api\Data\EventSubmissionRewardFact;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Registration\Api\RegistrationLookup;
use App\Features\Registration\Api\Data\RegistrationSnapshot;
use App\Features\Registration\Api\Data\CheckInSnapshot;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\ShareTracking\Api\ActivityRewardBusinessKey;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Reads actor-only facts from their authoritative source records.
 *
 * This adapter deliberately does not consult share receipts or affiliate
 * conversions. Those records determine attribution and are read only by the
 * share-outcome adapter.
 */
final class ActivityRewardSourceFactReader implements RewardSourceFactReader
{
    public function read(string $sourceBusinessKey): ?RewardSourceFact
    {
        try {
            $parsed = ActivityRewardBusinessKey::parse($sourceBusinessKey);
        } catch (Throwable) {
            return null;
        }

        $outcome = ShareOutcomeType::tryFrom($parsed['outcome_type']);

        if (! $outcome instanceof ShareOutcomeType) {
            return null;
        }

        if ($outcome === ShareOutcomeType::SavedSearchCreated) {
            return $this->savedSearchFact($sourceBusinessKey, $parsed['source_transition_id']);
        }
        if ($outcome === ShareOutcomeType::EventSubmission) {
            $submissionId = $this->prefixedSourceId($parsed['source_transition_id'], 'submission:');

            return $submissionId !== null ? $this->submissionFact($sourceBusinessKey, $submissionId) : null;
        }
        if (in_array($outcome, [ShareOutcomeType::EventRegistration, ShareOutcomeType::EventCheckin], true)) {
            return $this->admissionFact($sourceBusinessKey, $outcome, $parsed['source_transition_id']);
        }


        if ($this->isEngagementOutcome($outcome)) {
            $activity = $this->resolveEngagementActivity($outcome, $parsed['source_transition_id']);

            return $activity instanceof EngagementActivity
                ? $this->engagementFact($sourceBusinessKey, $outcome, $activity)
                : null;
        }

        if ($outcome !== ShareOutcomeType::Signup) {
            return null;
        }

        $sourceId = $this->prefixedSourceId($parsed['source_transition_id'], 'user:');
        $actor = $sourceId !== null ? User::query()->find($sourceId) : null;

        if (! $actor instanceof User) {
            return null;
        }

        $createdAt = $actor->getAttribute('created_at');

        if (! $createdAt instanceof DateTimeInterface && ! is_string($createdAt)) {
            return null;
        }

        try {
            $occurredAt = CarbonImmutable::parse($createdAt)->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }

        $metadata = $this->attribute($actor, 'metadata');
        $metadata = is_array($metadata) ? $metadata : [];

        return new RewardSourceFact(
            sourceBusinessKey: $sourceBusinessKey,
            outcome: $outcome->value,
            category: null,
            occurredAt: $occurredAt,
            verification: RewardVerificationLevel::Accepted,
            isFirstTime: true,
            actorId: (string) $actor->getKey(),
            sharerId: null,
            actorRole: RewardBeneficiaryRole::Participant,
            isClaimedIdentity: true,
            isAttributed: false,
            isAmbiguousDevice: $this->booleanSignal($actor, $metadata, 'is_ambiguous_device'),
            isBot: $this->booleanSignal($actor, $metadata, 'is_bot'),
            isCancelled: false,
            isValid: true,
            fraudStatus: $this->fraudStatusFor($actor, $metadata),
            correctionStatus: RewardSourceCorrectionStatus::Current,
        );
    }

    private function submissionFact(string $sourceBusinessKey, string $submissionId): ?RewardSourceFact
    {
        $reader = app(EventSubmissions::class);
        $source = $reader->findSubmission($submissionId);

        if (! $source instanceof EventSubmissionRewardFact || $source->occurredAt === null) {
            return null;
        }

        $actor = $this->userForMorph($source->submitterType, $source->submitterId);

        if (! $actor instanceof User) {
            return null;
        }

        try {
            $occurredAt = CarbonImmutable::parse($source->occurredAt)->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }

        $invalid = $source->status === 'rejected';

        return new RewardSourceFact(
            sourceBusinessKey: $sourceBusinessKey,
            outcome: ShareOutcomeType::EventSubmission->value,
            category: null,
            occurredAt: $occurredAt,
            verification: $source->status === 'approved'
                ? RewardVerificationLevel::ModeratedApproved
                : RewardVerificationLevel::Unverified,
            isFirstTime: ! $reader->hasEarlierSubmissionActivity(
                (string) $actor->getKey(),
                $actor->getMorphClass(),
                $occurredAt->setTimezone(config('app.timezone', 'UTC')),
                $source->id,
            ),
            actorId: (string) $actor->getKey(),
            sharerId: null,
            actorRole: RewardBeneficiaryRole::Participant,
            isClaimedIdentity: true,
            isAttributed: false,
            isAmbiguousDevice: $source->isAmbiguousDevice,
            isBot: $source->isBot,
            isCancelled: $invalid,
            isValid: ! $invalid,
            fraudStatus: $this->fraudStatus($source->fraudStatus ?? $this->scalarString($source->metadata['fraud_status'] ?? null)),
            correctionStatus: RewardSourceCorrectionStatus::Current,
        );
    }

    private function admissionFact(string $sourceBusinessKey, ShareOutcomeType $outcome, string $transitionId): ?RewardSourceFact
    {
        $registrationLookup = app(RegistrationLookup::class);
        $isRegistration = $outcome === ShareOutcomeType::EventRegistration;
        $id = $this->prefixedSourceId($transitionId, $isRegistration ? 'registration:' : 'checkin:');

        if ($id === null) {
            return null;
        }

        $source = $isRegistration
            ? $registrationLookup->findRegistration($id)
            : $registrationLookup->findCheckIn($id);

        if (! $source instanceof RegistrationSnapshot && ! $source instanceof CheckInSnapshot) {
            return null;
        }

        if ($source instanceof RegistrationSnapshot) {
            $actor = $this->userForMorph($source->registrantType, $source->registrantId);
            $timestamp = $source->registeredAt ?? $source->createdAt;
            $invalid = in_array($source->status, ['cancelled', 'rejected', 'expired', 'refunded'], true);
            $verification = in_array($source->status, ['confirmed', 'checked_in', 'completed'], true)
                ? RewardVerificationLevel::Accepted
                : RewardVerificationLevel::Unverified;
            $role = $source->totalAmount > 0 && $source->paymentStatus === 'paid'
                ? RewardBeneficiaryRole::Purchaser
                : RewardBeneficiaryRole::Participant;
            $previous = static fn (User $actor, CarbonImmutable $occurredAt): bool => $registrationLookup->hasEarlierRegistrationActivity(
                (string) $actor->getKey(),
                $actor->getMorphClass(),
                $occurredAt,
                $source->id,
            );
        } else {
            $attendee = $this->userForMorph($source->attendeeType, $source->attendeeId);
            $actor = $attendee ?? ($source->verifiedByUserId !== null ? User::query()->whereKey($source->verifiedByUserId)->first() : null);
            $timestamp = $source->checkedInAt ?? $source->createdAt;
            $invalid = $source->cancelledAt !== null
                || in_array($source->status, ['cancelled', 'rejected', 'voided'], true)
                || $source->attendanceType === 'did_not_attend';
            $verification = $source->verifiedByUserId !== null
                ? RewardVerificationLevel::StaffVerified
                : RewardVerificationLevel::Unverified;
            $role = ! $attendee instanceof User && $source->verifiedByUserId === (string) $actor?->getKey()
                ? RewardBeneficiaryRole::Staff
                : RewardBeneficiaryRole::Participant;
            $previous = static fn (User $actor, CarbonImmutable $occurredAt): bool => $registrationLookup->hasEarlierCheckInActivity(
                (string) $actor->getKey(),
                $actor->getMorphClass(),
                $occurredAt,
                $source->id,
            );
        }

        if (! $actor instanceof User || $timestamp === null) {
            return null;
        }

        try {
            $occurredAt = CarbonImmutable::parse($timestamp)->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }

        return new RewardSourceFact(
            sourceBusinessKey: $sourceBusinessKey,
            outcome: $outcome->value,
            category: null,
            occurredAt: $occurredAt,
            verification: $verification,
            isFirstTime: ! $previous($actor, $occurredAt->setTimezone(config('app.timezone', 'UTC'))),
            actorId: (string) $actor->getKey(),
            sharerId: null,
            actorRole: $role,
            isClaimedIdentity: true,
            isAttributed: false,
            isAmbiguousDevice: $source->isAmbiguousDevice,
            isBot: $source->isBot,
            isCancelled: $invalid,
            isValid: ! $invalid,
            fraudStatus: $this->fraudStatus($source->fraudStatus ?? $this->scalarString($source->metadata['fraud_status'] ?? null)),
            correctionStatus: RewardSourceCorrectionStatus::Current,
        );
    }

    private function savedSearchFact(string $sourceBusinessKey, string $sourceTransitionId): ?RewardSourceFact
    {
        $source = app(SavedSearchReader::class)->find(substr($sourceTransitionId, strlen('saved_search:')));

        if ($source === null || $source->createdAt === null) {
            return null;
        }

        $actor = $this->userForMorph($source->userType, $source->userId);

        if (! $actor instanceof User) {
            return null;
        }

        $occurredAt = $source->createdAt->setTimezone('UTC');

        return new RewardSourceFact(
            sourceBusinessKey: $sourceBusinessKey,
            outcome: ShareOutcomeType::SavedSearchCreated->value,
            category: null,
            occurredAt: $occurredAt,
            verification: RewardVerificationLevel::Accepted,
            isFirstTime: ! app(SavedSearchReader::class)->hasEarlierForOwner(
                $actor->getMorphClass(),
                (string) $actor->getKey(),
                $source->id,
                $occurredAt,
            ),
            actorId: (string) $actor->getKey(),
            sharerId: null,
            actorRole: RewardBeneficiaryRole::Participant,
            isClaimedIdentity: true,
            isAttributed: false,
            isAmbiguousDevice: false,
            isBot: false,
            isCancelled: false,
            isValid: true,
            fraudStatus: RewardSourceFraudStatus::Clear,
            correctionStatus: RewardSourceCorrectionStatus::Current,
        );
    }

    private function isEngagementOutcome(ShareOutcomeType $outcome): bool
    {
        return in_array($outcome, [
            ShareOutcomeType::EventSave,
            ShareOutcomeType::EventGoing,
            ShareOutcomeType::InstitutionFollow,
            ShareOutcomeType::PersonFollow,
            ShareOutcomeType::SeriesFollow,
            ShareOutcomeType::ReferenceFollow,
        ], true);
    }

    private function resolveEngagementActivity(
        ShareOutcomeType $outcome,
        string $sourceTransitionId,
    ): ?EngagementActivity {
        $reader = app(EngagementActivityReader::class);

        if ($outcome === ShareOutcomeType::EventSave) {
            $bookmarkId = $this->prefixedSourceId($sourceTransitionId, 'bookmark:');

            return $bookmarkId !== null ? $reader->bookmarkActivity($bookmarkId) : null;
        }

        if ($outcome === ShareOutcomeType::EventGoing) {
            $responseId = $this->prefixedSourceId($sourceTransitionId, 'response:');

            return $responseId !== null ? $reader->responseActivity($responseId) : null;
        }

        if (in_array($outcome, [
            ShareOutcomeType::InstitutionFollow,
            ShareOutcomeType::PersonFollow,
            ShareOutcomeType::SeriesFollow,
            ShareOutcomeType::ReferenceFollow,
        ], true)) {
            $followId = $this->prefixedSourceId($sourceTransitionId, 'follow:');

            return $followId !== null ? $reader->followActivity($followId) : null;
        }

        return null;
    }

    private function engagementFact(
        string $sourceBusinessKey,
        ShareOutcomeType $outcome,
        EngagementActivity $activity,
    ): ?RewardSourceFact {
        $actor = $this->userForMorph($activity->actorType, $activity->actorId);

        if (! $actor instanceof User) {
            return null;
        }

        $occurredAt = $activity->occurredAt;

        if (! $occurredAt instanceof CarbonImmutable) {
            return null;
        }

        $invalid = $this->engagementInvalidates($outcome, $activity->status);

        return new RewardSourceFact(
            sourceBusinessKey: $sourceBusinessKey,
            outcome: $outcome->value,
            category: null,
            occurredAt: $occurredAt,
            verification: RewardVerificationLevel::Accepted,
            isFirstTime: $this->isFirstEngagementActivity($outcome, $activity, $actor, $occurredAt),
            actorId: (string) $actor->getKey(),
            sharerId: null,
            actorRole: RewardBeneficiaryRole::Participant,
            isClaimedIdentity: true,
            isAttributed: false,
            isAmbiguousDevice: $this->metadataBoolean($activity->metadata, 'is_ambiguous_device'),
            isBot: $this->metadataBoolean($activity->metadata, 'is_bot'),
            isCancelled: $invalid,
            isValid: ! $invalid,
            fraudStatus: $this->metadataFraudStatus($activity->metadata),
            correctionStatus: RewardSourceCorrectionStatus::Current,
        );
    }

    private function isFirstEngagementActivity(
        ShareOutcomeType $outcome,
        EngagementActivity $activity,
        User $actor,
        CarbonImmutable $occurredAt,
    ): bool {
        $reader = app(EngagementActivityReader::class);
        $actorType = $actor->getMorphClass();
        $actorId = (string) $actor->getKey();

        return ! match ($outcome) {
            ShareOutcomeType::EventSave => $reader->hasEarlierBookmarkActivity(
                $actorType,
                $actorId,
                $occurredAt,
                $activity->id,
            ),
            ShareOutcomeType::EventGoing => $reader->hasEarlierResponseActivity(
                $actorType,
                $actorId,
                $occurredAt,
                $activity->id,
            ),
            default => $reader->hasEarlierFollowActivity(
                $actorType,
                $actorId,
                $occurredAt,
                $activity->id,
            ),
        };
    }

    private function engagementInvalidates(ShareOutcomeType $outcome, ?string $status): bool
    {
        return match ($outcome) {
            ShareOutcomeType::EventSave => ! in_array($status, [null, 'active'], true),
            ShareOutcomeType::EventGoing => in_array($status, ['cancelled', 'expired'], true),
            default => in_array($status, ['unfollowed', 'blocked'], true),
        };
    }

    private function prefixedSourceId(string $sourceTransitionId, string $prefix): ?string
    {
        if (! str_starts_with($sourceTransitionId, $prefix)) {
            return null;
        }

        $sourceId = substr($sourceTransitionId, strlen($prefix));

        return $sourceId !== '' ? $sourceId : null;
    }

    private function userForMorph(?string $type, ?string $id): ?User
    {
        if ($type === null || $id === null) {
            return null;
        }

        $resolvedType = Relation::getMorphedModel($type) ?? $type;

        if ($resolvedType !== User::class && $type !== (new User)->getMorphClass()) {
            return null;
        }

        return User::query()->whereKey($id)->first();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function fraudStatusFor(Model $source, array $metadata): RewardSourceFraudStatus
    {
        return $this->fraudStatus(
            $this->stringAttribute($source, 'fraud_status') ?? $this->scalarString($metadata['fraud_status'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function metadataFraudStatus(array $metadata): RewardSourceFraudStatus
    {
        return $this->fraudStatus($this->scalarString($metadata['fraud_status'] ?? null));
    }

    private function fraudStatus(?string $value): RewardSourceFraudStatus
    {
        return match ($value) {
            'confirmed' => RewardSourceFraudStatus::Confirmed,
            'detected', 'reviewed', 'suspect' => RewardSourceFraudStatus::Suspect,
            default => RewardSourceFraudStatus::Clear,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function booleanSignal(Model $source, array $metadata, string $key): bool
    {
        $value = $this->attribute($source, $key) ?? ($metadata[$key] ?? false);

        return in_array($value, [true, 1, '1'], true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function metadataBoolean(array $metadata, string $key): bool
    {
        return in_array($metadata[$key] ?? false, [true, 1, '1'], true);
    }

    private function stringAttribute(Model $source, string $attribute): ?string
    {
        return $this->scalarString($this->attribute($source, $attribute));
    }

    private function attribute(Model $source, string $attribute): mixed
    {
        $attributes = $source->getAttributes();

        if (! array_key_exists($attribute, $attributes) && ! array_key_exists($attribute, $source->getCasts())) {
            return null;
        }

        return $source->getAttribute($attribute);
    }

    private function scalarString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_object($value) && property_exists($value, 'value') && is_string($value->value)) {
            return $value->value;
        }

        if (is_object($value) && method_exists($value, 'getValue')) {
            return (string) $value->getValue();
        }

        return null;
    }
}
