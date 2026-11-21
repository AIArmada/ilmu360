<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use App\Features\Events\Actions\Scheduling\SyncEventScheduleAction;
use App\Features\Events\Api\AdminEventSaveResult;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\Support\AdminEventInputPreparer;
use App\Features\Events\Support\AdminEventRelationsSynchronizer;
use App\Features\Events\Support\AdminEventScheduleResolver;
use App\Features\Events\Support\AdminEventTimeMapper;
use App\Features\Events\Support\EventModel;
use App\Features\Prayer\Api\PrayerOffset;

/**
 * Persists one admin/advanced event save without moderation.
 *
 * This action owns event, schedule, classification, key-person and relation
 * writes. The admin entrypoint applies the requested initial moderation
 * status through the Events moderation API after persistence commits.
 */
final readonly class PersistEventAuthoringAction
{
    public function __construct(
        private AdminEventInputPreparer $inputPreparer,
        private AdminEventScheduleResolver $scheduleResolver,
        private AdminEventRelationsSynchronizer $relationsSynchronizer,
        private SyncEventOrganizerAction $syncEventOrganizer,
        private SyncEventScheduleAction $scheduleAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?string $eventId = null): AdminEventSaveResult
    {
        $creating = $eventId === null;
        $event = $creating
            ? EventModel::new('event')
            : EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);

        $input = $this->inputPreparer->prepare($data, $event, $creating);
        $state = $input['state'];
        $preparedSchedule = $this->scheduleResolver->resolve($data, $input, $event, $creating);
        $requestedStatus = $this->inputPreparer->requestedStatus($state, $event, $creating);
        $schedule = $preparedSchedule['schedule'];
        $attributes = $this->inputPreparer->attributes($input, $event, $creating, $schedule['timezone'], $schedule['starts_at']);

        if ($creating) {
            $event = EventModel::query('event')->create($attributes);
        } else {
            $event->fill($attributes);
        }

        // Sync before the event save so the location observer sees an already
        // aligned row and leaves the explicit space selection untouched.
        $event->syncLocation($input['venue_id'], $input['space_ids']);

        if (! $creating) {
            $event->save();
        }

        $this->scheduleAction->execute(
            $event,
            $preparedSchedule['schedule_kind'],
            startsAt: $schedule['starts_at'],
            endsAt: $schedule['ends_at'],
            timezone: $schedule['timezone'],
            timingMode: isset($schedule['timing_mode']) ? TimingMode::tryFrom($schedule['timing_mode']) : null,
            prayerReference: $schedule['prayer_reference'],
            prayerOffset: $schedule['prayer_offset'] !== null
                ? PrayerOffset::tryFrom((string) $schedule['prayer_offset'])?->minutes()
                : null,
            prayerDisplayText: $schedule['prayer_display_text'],
            prayerSource: $schedule['prayer_source'],
            prayerFetchedAt: $schedule['prayer_fetched_at'],
            prayerZone: $schedule['prayer_zone'] ?? null,
            prayerDate: AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $schedule['timezone']),
            prayerLat: isset($schedule['prayer_lat']) && is_numeric($schedule['prayer_lat']) ? (float) $schedule['prayer_lat'] : null,
            prayerLng: isset($schedule['prayer_lng']) && is_numeric($schedule['prayer_lng']) ? (float) $schedule['prayer_lng'] : null,
            prayerVenueId: $preparedSchedule['prayer_venue_id'],
            prayerInstitutionId: $preparedSchedule['prayer_institution_id'],
            prayerCountry: $schedule['prayer_country'],
        );

        $this->syncEventOrganizer->handle(
            (string) $event->getKey(),
            $input['primary_organizer_kind'],
            $input['organizer_id'],
        );
        $event->unsetRelation('primaryOrganizerInvolvement');

        $this->relationsSynchronizer->sync($event, $state, $data, $creating);

        return new AdminEventSaveResult(
            eventId: (string) $event->getKey(),
            requestedStatus: $requestedStatus,
            created: $creating,
        );
    }
}
