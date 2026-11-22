<?php

declare(strict_types=1);

namespace App\Features\Registration\Queries;

use App\Features\Registration\Api\Data\CheckInSourceFacts;
use App\Features\Registration\Api\Data\EventCheckInFacts;
use App\Features\Registration\Api\Data\EventRegistrationData;
use App\Features\Registration\Api\RegistrationLookup;
use App\Features\Registration\Api\Data\RegistrationSnapshot;
use App\Features\Registration\Api\Data\CheckInSnapshot;
use App\Features\Registration\Api\RegistrationNotificationAudience;
use App\Features\Registration\Models\EventCheckin;
use App\Features\Registration\Models\Registration;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class DatabaseRegistrationLookup implements RegistrationLookup, RegistrationNotificationAudience
{
    public function __construct(private RegistrationSnapshotProjector $snapshots) {}

    public function exists(string $registrationId): bool
    {
        return Registration::query()->whereKey($registrationId)->exists();
    }

    public function latestActiveForUser(string $eventId, string $userId): ?EventRegistrationData
    {
        $registration = Registration::query()
            ->where('event_id', $eventId)
            ->where('registrant_type', (new User)->getMorphClass())
            ->where('registrant_id', $userId)
            ->active()
            ->latest('created_at')
            ->first();

        if (! $registration instanceof Registration) {
            return null;
        }

        return new EventRegistrationData(
            id: (string) $registration->id,
            event_id: (string) $registration->event_id,
            user_id: $registration->registrant instanceof User ? (string) $registration->registrant->getKey() : null,
            name: $registration->resolvedName() ?? '',
            email: $registration->resolvedEmail(),
            phone: $registration->resolvedPhone(),
            status: $registration->statusValue(),
            created_at: $registration->created_at?->format(DATE_ATOM),
        );
    }

    public function hasCheckIn(string $eventId, string $attendeeId): bool
    {
        return EventCheckin::query()
            ->where('event_id', $eventId)
            ->where('attendee_id', $attendeeId)
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function lockedCheckInIdsForRegistration(string $registrationId): array
    {
        return EventCheckin::query()
            ->where('event_registration_id', $registrationId)
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function checkInSourceFacts(string $checkInId): ?CheckInSourceFacts
    {
        $checkIn = EventCheckin::query()->find($checkInId);

        if (! $checkIn instanceof EventCheckin) {
            return null;
        }

        $createdAt = $checkIn->getAttribute('created_at');

        return new CheckInSourceFacts(
            id: (string) $checkIn->getKey(),
            eventRegistrationId: $this->nullableString($checkIn->getAttribute('event_registration_id')),
            checkInSource: $this->nullableString($checkIn->getAttribute('check_in_source')),
            createdAt: $createdAt instanceof CarbonInterface ? $createdAt->toIso8601String() : null,
        );
    }

    /**
     * @return list<string>
     */
    public function activeRegistrantUserIds(string $eventId): array
    {
        $userMorphTypes = array_values(array_unique([(new User)->getMorphClass(), User::class]));

        return Registration::query()
            ->where('event_id', $eventId)
            ->active()
            ->whereIn('registrant_type', $userMorphTypes)
            ->pluck('registrant_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function checkInFacts(string $checkInId): ?EventCheckInFacts
    {
        $checkIn = EventCheckin::query()->find($checkInId);

        if (! $checkIn instanceof EventCheckin) {
            return null;
        }

        $eventId = $checkIn->getAttribute('event_id');
        $userId = $checkIn->getAttribute('attendee_id');

        if (! is_string($eventId) || $eventId === '' || ! is_string($userId) || $userId === '') {
            return null;
        }

        return new EventCheckInFacts(
            id: (string) $checkIn->getKey(),
            eventId: $eventId,
            userId: $userId,
        );
    }

    /**
     * @return list<string>
     */
    public function registrationIdsForAccount(string $accountId): array
    {
        return Registration::query()
            ->where('registrant_type', (new User)->getMorphClass())
            ->where('registrant_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function checkInIdsForAccount(string $accountId): array
    {
        return EventCheckin::query()
            ->where('attendee_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function verifiedCheckInIdsForAccount(string $accountId): array
    {
        return EventCheckin::query()
            ->where('verified_by_user_id', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function findRegistration(string $registrationId): ?RegistrationSnapshot
    {
        $registration = Registration::query()->with('event')->find($registrationId);

        return $registration instanceof Registration ? $this->snapshots->registration($registration, true) : null;
    }

    public function findCheckIn(string $checkInId): ?CheckInSnapshot
    {
        $checkIn = EventCheckin::query()->with(['event', 'verifiedBy:id,name'])->find($checkInId);

        return $checkIn instanceof EventCheckin ? $this->snapshots->checkIn($checkIn) : null;
    }

    public function hasEarlierRegistrationActivity(
        string $actorId,
        string $actorType,
        \DateTimeInterface $before,
        string $exceptId,
    ): bool {
        return Registration::query()
            ->where(function (Builder $query) use ($actorId, $actorType): void {
                $query->where('registrant_type', $actorType)
                    ->where('registrant_id', $actorId);
            })
            ->where('registered_at', '<', $before)
            ->whereKeyNot($exceptId)
            ->exists();
    }

    public function hasEarlierCheckInActivity(
        string $actorId,
        string $actorType,
        \DateTimeInterface $before,
        string $exceptId,
    ): bool {
        return EventCheckin::query()
            ->where(function (Builder $query) use ($actorId, $actorType): void {
                $query->where(function (Builder $attendee) use ($actorId, $actorType): void {
                    $attendee->where('attendee_type', $actorType)
                        ->where('attendee_id', $actorId);
                })->orWhere('verified_by_user_id', $actorId);
            })
            ->where('checked_in_at', '<', $before)
            ->whereKeyNot($exceptId)
            ->exists();
    }

    /** @return list<RegistrationSnapshot> */
    public function registrationsForAccount(string $accountId): array
    {
        return Registration::query()
            ->where('registrant_type', (new User)->getMorphClass())
            ->where('registrant_id', $accountId)
            ->with('event')
            ->latest()
            ->get()
            ->map(fn (Registration $registration): RegistrationSnapshot => $this->snapshots->registration($registration))
            ->all();
    }

    /** @param list<string> $registrationIds
     *  @return list<RegistrationSnapshot>
     */
    public function plannerRegistrationsForIds(array $registrationIds): array
    {
        return Registration::query()
            ->whereKey($registrationIds)
            ->active()
            ->with(array_map(static fn (string $relation): string => 'event.'.$relation, $this->plannerEventRelations()))
            ->latest()
            ->get()
            ->map(fn (Registration $registration): RegistrationSnapshot => $this->snapshots->registration($registration))
            ->all();
    }

    /** @return list<CheckInSnapshot> */
    public function checkInsForAccount(string $accountId): array
    {
        return EventCheckin::query()
            ->where('attendee_id', $accountId)
            ->with(['event', 'verifiedBy:id,name'])
            ->orderByDesc('checked_in_at')
            ->get()
            ->map(fn (EventCheckin $checkIn): CheckInSnapshot => $this->snapshots->checkIn($checkIn))
            ->all();
    }

    /** @return list<CheckInSnapshot> */
    public function plannerCheckInsForAccount(string $accountId): array
    {
        return EventCheckin::query()
            ->where('attendee_id', $accountId)
            ->with(array_map(static fn (string $relation): string => 'event.'.$relation, $this->plannerEventRelations()))
            ->orderByDesc('checked_in_at')
            ->get()
            ->map(fn (EventCheckin $checkIn): CheckInSnapshot => $this->snapshots->checkIn($checkIn))
            ->all();
    }

    /**
     * @return list<string>
     */
    public function confirmedRegistrationIdsForAccount(string $accountId): array
    {
        return Registration::query()
            ->where('registrant_type', (new User)->getMorphClass())
            ->where('registrant_id', $accountId)
            ->where('status', 'confirmed')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function checkInTransitionIdsForAccount(string $accountId): array
    {
        return EventCheckin::query()
            ->where('attendee_type', (new User)->getMorphClass())
            ->where('attendee_id', $accountId)
            ->where('attendance_type', 'check_in')
            ->whereNotNull('checked_in_at')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function plannerEventRelations(): array
    {
        return ['media', 'institution', 'institution.media', 'venue'];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
