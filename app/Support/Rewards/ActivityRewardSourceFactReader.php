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
use App\Enums\ShareOutcomeType;
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

        $outcome = ShareOutcomeType::tryFrom($parsed['outcome_type']);

        if (! $outcome instanceof ShareOutcomeType) {
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
        ShareOutcomeType $outcome,
        Model $source,
        User $actor,
        CarbonImmutable $occurredAt,
    ): bool {
        if ($outcome === ShareOutcomeType::Signup) {
            return true;
        }

        [$modelClass, $actorColumns, $occurredColumn] = match ($outcome) {
            ShareOutcomeType::EventRegistration => [Registration::class, ['registrant_type', 'registrant_id'], 'registered_at'],
            ShareOutcomeType::EventCheckin => [EventCheckin::class, ['attendee_type', 'attendee_id'], 'checked_in_at'],
            ShareOutcomeType::EventSubmission => [EventSubmission::class, ['submitter_type', 'submitter_id'], 'submitted_at'],
            ShareOutcomeType::EventSave => [Bookmark::class, ['bookmarker_type', 'bookmarker_id'], 'bookmarked_at'],
            ShareOutcomeType::EventGoing => [Response::class, ['responder_type', 'responder_id'], 'responded_at'],
            ShareOutcomeType::InstitutionFollow,
            ShareOutcomeType::PersonFollow,
            ShareOutcomeType::SeriesFollow,
            ShareOutcomeType::ReferenceFollow => [Follow::class, ['follower_type', 'follower_id'], 'followed_at'],
            ShareOutcomeType::SavedSearchCreated => [SavedSearch::class, ['user_type', 'user_id'], 'created_at'],
        };

        $query = $modelClass::query()
            ->where(function ($query) use ($actor, $actorColumns, $outcome): void {
                $query->where(function ($query) use ($actor, $actorColumns): void {
                    $query->where($actorColumns[0], $actor->getMorphClass())
                        ->where($actorColumns[1], (string) $actor->getKey());
                });

                if ($outcome === ShareOutcomeType::EventCheckin) {
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
