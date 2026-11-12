<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\VenueSpaceType;
use App\Features\Events\Api\EventLocations;
use App\Features\Events\Support\EventModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Owns the event-level event_locations rows behind Event::syncLocation and the
 * default_venue/institution selection observer.
 */
final readonly class SyncEventLocationsAction implements EventLocations
{
    /**
     * @param  list<string>  $spaceIds
     */
    public function sync(string $eventId, ?string $venueId = null, array $spaceIds = []): void
    {
        $existingSnapshots = EventLocation::query()
            ->where('event_id', $eventId)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->whereNotNull('venue_space_id')
            ->pluck('space_name_snapshot', 'venue_space_id')
            ->all();

        $spaces = EventModel::query('space')
            ->whereKey($spaceIds)
            ->get(['id', 'name', 'space_type'])
            ->keyBy(fn (Model $space): string => (string) $space->getKey());

        $spaceTypeIds = VenueSpaceType::query()
            ->whereIn('code', $spaces->pluck('space_type')->filter()->unique()->values())
            ->pluck('id', 'code')
            ->all();

        EventLocation::query()
            ->where('event_id', $eventId)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->delete();

        if (($venueId !== null && $venueId !== '') || $spaceIds !== []) {
            $first = true;

            foreach ($spaceIds as $i => $spaceId) {
                if (! is_string($spaceId) || $spaceId === '') {
                    continue;
                }

                $location = EventLocation::query()->create([
                    'event_id' => $eventId,
                    'event_occurrence_id' => null,
                    'event_session_id' => null,
                    'location_role' => $first ? 'primary' : 'additional',
                    'venue_id' => $first ? $venueId : null,
                    'venue_space_id' => $spaceId,
                    'venue_space_type_id' => isset($spaces[$spaceId])
                        ? ($spaceTypeIds[(string) $spaces[$spaceId]->getAttribute('space_type')] ?? null)
                        : null,
                    'space_name_snapshot' => array_key_exists($spaceId, $existingSnapshots)
                        ? $existingSnapshots[$spaceId]
                        : ($spaces[$spaceId]->getAttribute('name') ?? null),
                    'visibility' => 'public',
                    'status' => 'active',
                    'sort_order' => $i,
                ]);

                if (array_key_exists($spaceId, $existingSnapshots)) {
                    $location->updateQuietly([
                        'space_name_snapshot' => $existingSnapshots[$spaceId],
                    ]);
                }

                $first = false;
            }

            if ($first && $venueId !== null && $venueId !== '') {
                EventLocation::query()->create([
                    'event_id' => $eventId,
                    'event_occurrence_id' => null,
                    'event_session_id' => null,
                    'location_role' => 'primary',
                    'venue_id' => $venueId,
                    'venue_space_id' => null,
                    'visibility' => 'public',
                    'status' => 'active',
                    'sort_order' => 0,
                ]);
            }
        }
    }

    public function syncPrimaryFromSelection(
        string $eventId,
        bool $venueChanged,
        bool $institutionChanged,
    ): bool {
        $event = EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);

        $venueId = $event->getAttribute('default_venue_id');
        $venueId = is_string($venueId) && $venueId !== '' ? $venueId : null;

        $primaryLocation = EventLocation::query()
            ->where('event_id', $eventId)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->where('location_role', 'primary')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($venueId === null) {
            // Only clear a stale venue-backed primary row. A null-venue row
            // can still carry an institution/space selection and must survive.
            if (
                $primaryLocation instanceof EventLocation
                && $primaryLocation->getAttribute('venue_id') !== null
            ) {
                $this->sync($eventId);

                return true;
            }

            return false;
        }

        if (
            $primaryLocation instanceof EventLocation
            && (string) $primaryLocation->getAttribute('venue_id') === $venueId
        ) {
            return false;
        }

        // Institution-only changes must preserve the explicit event-level
        // space selection; a venue change keeps the historical replace
        // semantics (the newly selected venue owns its spaces).
        $spaceIds = $institutionChanged && ! $venueChanged
            ? $this->eventLevelSpaceIds($eventId)
            : [];

        $this->sync($eventId, $venueId, $spaceIds);

        return true;
    }

    /**
     * @return list<string>
     */
    private function eventLevelSpaceIds(string $eventId): array
    {
        return EventLocation::query()
            ->where('event_id', $eventId)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->whereNotNull('venue_space_id')
            ->orderBy('sort_order')
            ->pluck('venue_space_id')
            ->filter(fn (mixed $spaceId): bool => is_string($spaceId) && $spaceId !== '')
            ->values()
            ->all();
    }
}
