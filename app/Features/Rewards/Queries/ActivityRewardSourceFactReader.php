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
use App\Features\Events\Api\Orm\EventSubmissions;
use App\Features\Registration\Api\Orm\RegistrationLookup;
use App\Features\Registration\Models\EventCheckin;
use App\Features\Registration\Models\Registration;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\ShareTracking\Api\ActivityRewardBusinessKey;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
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

        if ($this->isEngagementOutcome($outcome)) {
            $activity = $this->resolveEngagementActivity($outcome, $parsed['source_transition_id']);

            return $activity instanceof EngagementActivity
                ? $this->engagementFact($sourceBusinessKey, $outcome, $activity)
                : null;
        }

        $source = $this->resolveSourceRecord($parsed['source_transition_id']);

        if (! $source instanceof Model) {
            return null;
        }

        $actor = $this->actorFor($outcome, $source);

        if (! $actor instanceof User) {
            return null;
        }

        $occurredAt = $this->occurredAtFor($outcome, $source);

        if (! $occurredAt instanceof CarbonImmutable) {
            return null;
        }

        $invalidates = $this->sourceInvalidates($outcome, $source);
        $metadata = $this->attribute($source, 'metadata');
        $metadata = is_array($metadata) ? $metadata : [];

        return new RewardSourceFact(
            sourceBusinessKey: $sourceBusinessKey,
            outcome: $outcome->value,
            category: null,
            occurredAt: $occurredAt,
            verification: $this->verificationFor($outcome, $source),
            isFirstTime: $this->isFirstTime($outcome, (string) $source->getKey(), $actor, $occurredAt),
            actorId: (string) $actor->getKey(),
            sharerId: null,
            actorRole: $this->actorRoleFor($outcome, $source, $actor),
            isClaimedIdentity: true,
            isAttributed: false,
            isAmbiguousDevice: $this->booleanSignal($source, $metadata, 'is_ambiguous_device'),
            isBot: $this->booleanSignal($source, $metadata, 'is_bot'),
            isCancelled: $invalidates['cancelled'],
            isValid: ! $invalidates['invalid'],
            fraudStatus: $this->fraudStatusFor($source, $metadata),
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
            isFirstTime: $this->isFirstTime(ShareOutcomeType::SavedSearchCreated, $source->id, $actor, $occurredAt),
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

    private function resolveSourceRecord(string $sourceTransitionId): ?Model
    {
        /** @var array<string, callable(string): mixed> $sources */
        $sources = [
            'user:' => static fn (string $id): mixed => User::query()->find($id),
            'registration:' => static fn (string $id): mixed => app(RegistrationLookup::class)->findRegistration($id),
            'checkin:' => static fn (string $id): mixed => app(RegistrationLookup::class)->findCheckIn($id),
            'submission:' => static fn (string $id): mixed => app(EventSubmissions::class)->findSubmission($id),
        ];

        foreach ($sources as $prefix => $resolve) {
            if (! str_starts_with($sourceTransitionId, $prefix)) {
                continue;
            }

            $sourceId = substr($sourceTransitionId, strlen($prefix));

            if ($sourceId === '') {
                return null;
            }

            $source = $resolve($sourceId);

            return $source instanceof Model ? $source : null;
        }

        return null;
    }

    private function actorFor(ShareOutcomeType $outcome, Model $source): ?User
    {
        if ($outcome === ShareOutcomeType::Signup && $source instanceof User) {
            return $source;
        }

        if ($outcome === ShareOutcomeType::EventRegistration && $source instanceof Registration) {
            return $this->userForMorph(
                $this->stringAttribute($source, 'registrant_type'),
                $this->stringAttribute($source, 'registrant_id'),
            );
        }

        if ($outcome === ShareOutcomeType::EventCheckin && $source instanceof EventCheckin) {
            $attendee = $this->userForMorph(
                $this->stringAttribute($source, 'attendee_type'),
                $this->stringAttribute($source, 'attendee_id'),
            );

            if ($attendee instanceof User) {
                return $attendee;
            }

            $verifierId = $this->stringAttribute($source, 'verified_by_user_id');

            return $verifierId !== null ? User::query()->whereKey($verifierId)->first() : null;
        }

        $actorColumns = match ($outcome) {
            ShareOutcomeType::EventSubmission => ['submitter_type', 'submitter_id'],
            ShareOutcomeType::EventSave => ['bookmarker_type', 'bookmarker_id'],
            ShareOutcomeType::EventGoing => ['responder_type', 'responder_id'],
            ShareOutcomeType::InstitutionFollow,
            ShareOutcomeType::PersonFollow,
            ShareOutcomeType::SeriesFollow,
            ShareOutcomeType::ReferenceFollow => ['follower_type', 'follower_id'],
            ShareOutcomeType::SavedSearchCreated => ['user_type', 'user_id'],
            default => null,
        };

        if ($actorColumns === null) {
            return null;
        }

        return $this->userForMorph(
            $this->stringAttribute($source, $actorColumns[0]),
            $this->stringAttribute($source, $actorColumns[1]),
        );
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

    private function occurredAtFor(ShareOutcomeType $outcome, Model $source): ?CarbonImmutable
    {
        $column = match ($outcome) {
            ShareOutcomeType::Signup => 'created_at',
            ShareOutcomeType::EventRegistration => 'registered_at',
            ShareOutcomeType::EventCheckin => 'checked_in_at',
            ShareOutcomeType::EventSubmission => 'submitted_at',
            ShareOutcomeType::EventSave => 'bookmarked_at',
            ShareOutcomeType::EventGoing => 'responded_at',
            ShareOutcomeType::InstitutionFollow,
            ShareOutcomeType::PersonFollow,
            ShareOutcomeType::SeriesFollow,
            ShareOutcomeType::ReferenceFollow => 'followed_at',
            ShareOutcomeType::SavedSearchCreated => 'created_at',
        };

        $value = $this->attribute($source, $column) ?? $this->attribute($source, 'created_at');

        if (! $value instanceof DateTimeInterface && ! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{cancelled: bool, invalid: bool}
     */
    private function sourceInvalidates(ShareOutcomeType $outcome, Model $source): array
    {
        $status = $this->stringAttribute($source, 'status');
        $cancelled = $this->attribute($source, 'cancelled_at') !== null;

        $invalid = match ($outcome) {
            ShareOutcomeType::EventRegistration => in_array($status, ['cancelled', 'rejected', 'expired', 'refunded'], true),
            ShareOutcomeType::EventCheckin => $cancelled
                || in_array($status, ['cancelled', 'rejected', 'voided'], true)
                || $this->stringAttribute($source, 'attendance_type') === 'did_not_attend',
            ShareOutcomeType::EventSubmission => $status === 'rejected',
            ShareOutcomeType::EventSave => ! in_array($status, [null, 'active'], true),
            ShareOutcomeType::EventGoing => in_array($status, ['cancelled', 'expired'], true),
            ShareOutcomeType::InstitutionFollow,
            ShareOutcomeType::PersonFollow,
            ShareOutcomeType::SeriesFollow,
            ShareOutcomeType::ReferenceFollow => in_array($status, ['unfollowed', 'blocked'], true),
            default => false,
        };

        return [
            'cancelled' => $invalid,
            'invalid' => $invalid,
        ];
    }

    private function verificationFor(ShareOutcomeType $outcome, Model $source): RewardVerificationLevel
    {
        if ($outcome === ShareOutcomeType::EventCheckin) {
            return $this->stringAttribute($source, 'verified_by_user_id') !== null
                ? RewardVerificationLevel::StaffVerified
                : RewardVerificationLevel::Unverified;
        }

        if ($outcome === ShareOutcomeType::EventRegistration) {
            return in_array($this->stringAttribute($source, 'status'), ['confirmed', 'checked_in', 'completed'], true)
                ? RewardVerificationLevel::Accepted
                : RewardVerificationLevel::Unverified;
        }

        if ($outcome === ShareOutcomeType::EventSubmission) {
            return $this->stringAttribute($source, 'status') === 'approved'
                ? RewardVerificationLevel::ModeratedApproved
                : RewardVerificationLevel::Unverified;
        }

        return RewardVerificationLevel::Accepted;
    }

    private function actorRoleFor(ShareOutcomeType $outcome, Model $source, User $actor): RewardBeneficiaryRole
    {
        if ($outcome === ShareOutcomeType::EventRegistration
            && (float) $source->getAttribute('total_amount') > 0
            && $this->stringAttribute($source, 'payment_status') === 'paid'
        ) {
            return RewardBeneficiaryRole::Purchaser;
        }

        if ($outcome === ShareOutcomeType::EventCheckin
            && $source instanceof EventCheckin
            && ! $this->userForMorph(
                $this->stringAttribute($source, 'attendee_type'),
                $this->stringAttribute($source, 'attendee_id'),
            ) instanceof User
            && $this->stringAttribute($source, 'verified_by_user_id') === (string) $actor->getKey()
        ) {
            return RewardBeneficiaryRole::Staff;
        }

        return RewardBeneficiaryRole::Participant;
    }

    private function isFirstTime(
        ShareOutcomeType $outcome,
        string $sourceId,
        User $actor,
        CarbonImmutable $occurredAt,
    ): bool {
        if ($outcome === ShareOutcomeType::Signup) {
            return true;
        }

        if ($outcome === ShareOutcomeType::SavedSearchCreated) {
            return ! app(SavedSearchReader::class)->hasEarlierForOwner(
                $actor->getMorphClass(),
                (string) $actor->getKey(),
                $sourceId,
                $occurredAt,
            );
        }

        $before = $occurredAt->setTimezone(config('app.timezone', 'UTC'));

        return match ($outcome) {
            ShareOutcomeType::EventRegistration => ! app(RegistrationLookup::class)->hasEarlierRegistrationActivity(
                (string) $actor->getKey(),
                $actor->getMorphClass(),
                $before,
                $sourceId,
            ),
            ShareOutcomeType::EventCheckin => ! app(RegistrationLookup::class)->hasEarlierCheckInActivity(
                (string) $actor->getKey(),
                $actor->getMorphClass(),
                $before,
                $sourceId,
            ),
            ShareOutcomeType::EventSubmission => ! app(EventSubmissions::class)->hasEarlierSubmissionActivity(
                (string) $actor->getKey(),
                $actor->getMorphClass(),
                $before,
                $sourceId,
            ),
            default => throw new LogicException('Engagement outcomes are resolved through the engagement reader.'),
        };
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
