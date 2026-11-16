<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Contracts\EventLifecycleWorkflow;
use AIArmada\Events\Models\Event as PackageEvent;
use App\Features\Events\Support\EventModel;
use App\Models\Event;
use App\Features\Places\Api\SpaceEligibility;
use App\Shared\Events\Enums\EventChangeType;
use Illuminate\Validation\ValidationException;

/**
 * Apply the event-side mutations represented by a published change announcement.
 */
final readonly class ApplyChangeAnnouncementMutationsAction
{
    public function __construct(
        private EventLifecycleWorkflow $eventLifecycleWorkflow,
        private SpaceEligibility $spaceEligibilityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     * @return list<string>
     */
    public function handle(string $eventId, string $type, array $changes): array
    {
        /** @var Event $event */
        $event = EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);

        $eventType = EventChangeType::from($type);
        $changedFields = [];

        foreach ($this->supportedScalarFields() as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            $event->{$field} = $changes[$field];
            $changedFields[] = $field;
        }

        $changedFields = array_merge($changedFields, $this->applyLocationChanges($event, $changes));

        $occurrence = $event->primaryOccurrence;

        if ($eventType === EventChangeType::Cancelled) {
            if ((string) $event->status !== 'cancelled') {
                $cancelledAt = now();
                $event->status = PackageEvent::CANCELLED;
                $event->cancelled_at = $cancelledAt;
                $event->last_state_change_at = $cancelledAt;
            }

            $changedFields[] = 'status';

            if ($occurrence && (string) $occurrence->status !== 'cancelled') {
                $occurrence->cancel();
                $changedFields[] = 'occurrence_status';
            } elseif ($occurrence) {
                $changedFields[] = 'occurrence_status';
            }
        }

        if ($eventType === EventChangeType::Postponed && $occurrence) {
            $occurrence->postpone();
            $changedFields[] = 'occurrence_status';
        }

        if (in_array($eventType, [
            EventChangeType::RescheduledEarlier,
            EventChangeType::RescheduledLater,
            EventChangeType::ScheduleChanged,
        ], true)) {
            $startsAt = $changes['starts_at'] ?? null;
            $endsAt = $changes['ends_at'] ?? null;

            if ($occurrence && $startsAt && $endsAt) {
                $this->eventLifecycleWorkflow->reschedule($occurrence, $startsAt, $endsAt);
                $changedFields[] = 'occurrence_status';
            }
        }

        $event->save();

        return $changedFields;
    }

    /**
     * Apply current venue and space selections through the Events location API.
     *
     * @param  array<string, mixed>  $changes
     * @return list<string>
     */
    private function applyLocationChanges(Event $event, array $changes): array
    {
        $hasInstitutionChange = array_key_exists('institution_id', $changes);
        $hasVenueChange = array_key_exists('venue_id', $changes);
        $hasSpaceChange = array_key_exists('space_ids', $changes);

        if (! $hasInstitutionChange && ! $hasVenueChange && ! $hasSpaceChange) {
            return [];
        }

        $event->loadMissing('locations');
        $currentInstitutionId = $this->normalizeOptionalString($event->institution_id, 'institution_id');
        $currentVenueId = $this->normalizeOptionalString($event->default_venue_id, 'venue_id');
        $currentSpaceIds = $this->existingSpaceIds($event);

        $institutionId = $hasInstitutionChange
            ? $this->normalizeOptionalString($changes['institution_id'], 'institution_id')
            : $currentInstitutionId;
        $venueId = $hasVenueChange
            ? $this->normalizeOptionalString($changes['venue_id'], 'venue_id')
            : $currentVenueId;

        if ($hasInstitutionChange && $institutionId !== null && ! $hasVenueChange) {
            $venueId = null;
        }

        if ($hasVenueChange && $venueId !== null && ! $hasInstitutionChange) {
            $institutionId = null;
        }

        $spaceIds = $hasSpaceChange ? $this->normalizeSpaceIds($changes['space_ids']) : $currentSpaceIds;

        $this->validateLocationSelection($institutionId, $venueId, $spaceIds);

        $event->institution_id = $institutionId;
        $event->default_venue_id = $venueId;

        // Sync before save so the default-venue observer keeps explicit spaces.
        $event->syncLocation($venueId, $spaceIds);

        $changedFields = [];

        if ($hasInstitutionChange || $currentInstitutionId !== $institutionId) {
            $changedFields[] = 'institution_id';
        }

        if ($hasVenueChange || $currentVenueId !== $venueId) {
            $changedFields[] = 'venue_id';
        }

        if ($hasSpaceChange || $currentSpaceIds !== $spaceIds) {
            $changedFields[] = 'space_ids';
        }

        return $changedFields;
    }

    /**
     * @param  list<string>  $spaceIds
     */
    private function validateLocationSelection(?string $institutionId, ?string $venueId, array $spaceIds): void
    {
        if ($institutionId !== null && $venueId !== null) {
            $message = __('Pilih institusi atau venue, bukan kedua-duanya sekali.');

            throw ValidationException::withMessages([
                'institution_id' => $message,
                'venue_id' => $message,
            ]);
        }

        if ($institutionId !== null && ! EventModel::query('institution')->whereKey($institutionId)->exists()) {
            throw ValidationException::withMessages([
                'institution_id' => __('The selected institution is invalid.'),
            ]);
        }

        if ($venueId !== null && ! EventModel::query('venue')->whereKey($venueId)->exists()) {
            throw ValidationException::withMessages([
                'venue_id' => __('The selected venue is invalid.'),
            ]);
        }

        if ($spaceIds === []) {
            return;
        }

        if ($venueId !== null) {
            $this->spaceEligibilityResolver->validateVenueSelection($venueId, $spaceIds);

            return;
        }

        if ($institutionId !== null) {
            $this->spaceEligibilityResolver->validateInstitutionSelection($institutionId, $spaceIds);

            return;
        }

        throw ValidationException::withMessages([
            'space_ids' => __('Ruang memerlukan institusi atau venue.'),
        ]);
    }

    /** @return list<string> */
    private function existingSpaceIds(Event $event): array
    {
        return $event->locations
            ->sortBy('sort_order')
            ->pluck('venue_space_id')
            ->filter()
            ->map(strval(...))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function normalizeSpaceIds(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw ValidationException::withMessages([
                'space_ids' => __('The selected spaces are invalid.'),
            ]);
        }

        $spaceIds = [];

        foreach ($value as $spaceId) {
            if (! is_string($spaceId) || trim($spaceId) === '') {
                throw ValidationException::withMessages([
                    'space_ids' => __('The selected spaces are invalid.'),
                ]);
            }

            $spaceIds[] = trim($spaceId);
        }

        return array_values(array_unique($spaceIds));
    }

    private function normalizeOptionalString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $field => __('The selected value is invalid.'),
            ]);
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function supportedScalarFields(): array
    {
        return [
            'title',
            'timezone',
            'event_url',
            'live_url',
            'recording_url',
        ];
    }
}
