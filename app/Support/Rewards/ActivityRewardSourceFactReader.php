<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Response;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardSourceCorrectionStatus;
use AIArmada\Rewards\Enums\RewardSourceFraudStatus;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use AIArmada\Rewards\Support\RewardSourceFact;
use App\Enums\DawahShareOutcomeType;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Registration;
use App\Models\SavedSearch;
use App\Models\User;
use App\Support\ShareTracking\ActivityRewardBusinessKey;
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

        $outcome = DawahShareOutcomeType::tryFrom($parsed['outcome_type']);

        if (! $outcome instanceof DawahShareOutcomeType) {
            return null;
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
            isFirstTime: $this->isFirstTime($outcome, $source, $actor, $occurredAt),
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
            correctionId: null,
        );
    }

    private function resolveSourceRecord(string $sourceTransitionId): ?Model
    {
        $sources = [
            'user:' => User::class,
            'registration:' => Registration::class,
            'checkin:' => EventCheckin::class,
            'submission:' => EventSubmission::class,
            'bookmark:' => Bookmark::class,
            'response:' => Response::class,
            'follow:' => Follow::class,
            'saved_search:' => SavedSearch::class,
        ];

        foreach ($sources as $prefix => $modelClass) {
            if (! str_starts_with($sourceTransitionId, $prefix)) {
                continue;
            }

            $sourceId = substr($sourceTransitionId, strlen($prefix));

            if ($sourceId === '') {
                return null;
            }

            return $modelClass::query()->whereKey($sourceId)->first();
        }

        return null;
    }

    private function actorFor(DawahShareOutcomeType $outcome, Model $source): ?User
    {
        if ($outcome === DawahShareOutcomeType::Signup && $source instanceof User) {
            return $source;
        }

        if ($outcome === DawahShareOutcomeType::EventRegistration && $source instanceof Registration) {
            return $this->userForMorph(
                $this->stringAttribute($source, 'registrant_type'),
                $this->stringAttribute($source, 'registrant_id'),
            );
        }

        if ($outcome === DawahShareOutcomeType::EventCheckin && $source instanceof EventCheckin) {
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
            DawahShareOutcomeType::EventSubmission => ['submitter_type', 'submitter_id'],
            DawahShareOutcomeType::EventSave => ['bookmarker_type', 'bookmarker_id'],
            DawahShareOutcomeType::EventGoing => ['responder_type', 'responder_id'],
            DawahShareOutcomeType::InstitutionFollow,
            DawahShareOutcomeType::PersonFollow,
            DawahShareOutcomeType::SeriesFollow,
            DawahShareOutcomeType::ReferenceFollow => ['follower_type', 'follower_id'],
            DawahShareOutcomeType::SavedSearchCreated => ['user_type', 'user_id'],
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

    private function occurredAtFor(DawahShareOutcomeType $outcome, Model $source): ?CarbonImmutable
    {
        $column = match ($outcome) {
            DawahShareOutcomeType::Signup => 'created_at',
            DawahShareOutcomeType::EventRegistration => 'registered_at',
            DawahShareOutcomeType::EventCheckin => 'checked_in_at',
            DawahShareOutcomeType::EventSubmission => 'submitted_at',
            DawahShareOutcomeType::EventSave => 'bookmarked_at',
            DawahShareOutcomeType::EventGoing => 'responded_at',
            DawahShareOutcomeType::InstitutionFollow,
            DawahShareOutcomeType::PersonFollow,
            DawahShareOutcomeType::SeriesFollow,
            DawahShareOutcomeType::ReferenceFollow => 'followed_at',
            DawahShareOutcomeType::SavedSearchCreated => 'created_at',
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
    private function sourceInvalidates(DawahShareOutcomeType $outcome, Model $source): array
    {
        $status = $this->stringAttribute($source, 'status');
        $cancelled = $this->attribute($source, 'cancelled_at') !== null;

        $invalid = match ($outcome) {
            DawahShareOutcomeType::EventRegistration => in_array($status, ['cancelled', 'rejected', 'expired', 'refunded'], true),
            DawahShareOutcomeType::EventCheckin => $cancelled
                || in_array($status, ['cancelled', 'rejected', 'voided'], true)
                || $this->stringAttribute($source, 'attendance_type') === 'did_not_attend',
            DawahShareOutcomeType::EventSubmission => $status === 'rejected',
            DawahShareOutcomeType::EventSave => ! in_array($status, [null, 'active'], true),
            DawahShareOutcomeType::EventGoing => in_array($status, ['cancelled', 'expired'], true),
            DawahShareOutcomeType::InstitutionFollow,
            DawahShareOutcomeType::PersonFollow,
            DawahShareOutcomeType::SeriesFollow,
            DawahShareOutcomeType::ReferenceFollow => in_array($status, ['unfollowed', 'blocked'], true),
            default => false,
        };

        return [
            'cancelled' => $invalid,
            'invalid' => $invalid,
        ];
    }

    private function verificationFor(DawahShareOutcomeType $outcome, Model $source): RewardVerificationLevel
    {
        if ($outcome === DawahShareOutcomeType::EventCheckin) {
            return $this->stringAttribute($source, 'verified_by_user_id') !== null
                ? RewardVerificationLevel::StaffVerified
                : RewardVerificationLevel::Unverified;
        }

        if ($outcome === DawahShareOutcomeType::EventRegistration) {
            return in_array($this->stringAttribute($source, 'status'), ['confirmed', 'checked_in', 'completed'], true)
                ? RewardVerificationLevel::Accepted
                : RewardVerificationLevel::Unverified;
        }

        if ($outcome === DawahShareOutcomeType::EventSubmission) {
            return $this->stringAttribute($source, 'status') === 'approved'
                ? RewardVerificationLevel::ModeratedApproved
                : RewardVerificationLevel::Unverified;
        }

        return RewardVerificationLevel::Accepted;
    }

    private function actorRoleFor(DawahShareOutcomeType $outcome, Model $source, User $actor): RewardBeneficiaryRole
    {
        if ($outcome === DawahShareOutcomeType::EventRegistration
            && (float) $source->getAttribute('total_amount') > 0
            && $this->stringAttribute($source, 'payment_status') === 'paid'
        ) {
            return RewardBeneficiaryRole::Purchaser;
        }

        if ($outcome === DawahShareOutcomeType::EventCheckin
            && $source instanceof EventCheckin
            && $this->userForMorph(
                $this->stringAttribute($source, 'attendee_type'),
                $this->stringAttribute($source, 'attendee_id'),
            ) === null
            && $this->stringAttribute($source, 'verified_by_user_id') === (string) $actor->getKey()
        ) {
            return RewardBeneficiaryRole::Staff;
        }

        return RewardBeneficiaryRole::Participant;
    }

    private function isFirstTime(
        DawahShareOutcomeType $outcome,
        Model $source,
        User $actor,
        CarbonImmutable $occurredAt,
    ): bool {
        if ($outcome === DawahShareOutcomeType::Signup) {
            return true;
        }

        [$modelClass, $actorColumns, $occurredColumn] = match ($outcome) {
            DawahShareOutcomeType::EventRegistration => [Registration::class, ['registrant_type', 'registrant_id'], 'registered_at'],
            DawahShareOutcomeType::EventCheckin => [EventCheckin::class, ['attendee_type', 'attendee_id'], 'checked_in_at'],
            DawahShareOutcomeType::EventSubmission => [EventSubmission::class, ['submitter_type', 'submitter_id'], 'submitted_at'],
            DawahShareOutcomeType::EventSave => [Bookmark::class, ['bookmarker_type', 'bookmarker_id'], 'bookmarked_at'],
            DawahShareOutcomeType::EventGoing => [Response::class, ['responder_type', 'responder_id'], 'responded_at'],
            DawahShareOutcomeType::InstitutionFollow,
            DawahShareOutcomeType::PersonFollow,
            DawahShareOutcomeType::SeriesFollow,
            DawahShareOutcomeType::ReferenceFollow => [Follow::class, ['follower_type', 'follower_id'], 'followed_at'],
            DawahShareOutcomeType::SavedSearchCreated => [SavedSearch::class, ['user_type', 'user_id'], 'created_at'],
        };

        $query = $modelClass::query()
            ->where(function ($query) use ($actor, $actorColumns, $outcome): void {
                $query->where(function ($query) use ($actor, $actorColumns): void {
                    $query->where($actorColumns[0], $actor->getMorphClass())
                        ->where($actorColumns[1], (string) $actor->getKey());
                });

                if ($outcome === DawahShareOutcomeType::EventCheckin) {
                    $query->orWhere('verified_by_user_id', (string) $actor->getKey());
                }
            })
            ->where($occurredColumn, '<', $occurredAt->setTimezone(config('app.timezone', 'UTC')))
            ->whereKeyNot((string) $source->getKey());

        return ! $query->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function fraudStatusFor(Model $source, array $metadata): RewardSourceFraudStatus
    {
        $value = $this->stringAttribute($source, 'fraud_status') ?? $this->scalarString($metadata['fraud_status'] ?? null);

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

        return $value === true || $value === 1 || $value === '1';
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
