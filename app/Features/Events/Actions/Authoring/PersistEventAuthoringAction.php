<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use App\Features\Events\Actions\Scheduling\SyncEventScheduleAction;
use App\Features\Events\Api\AdminEventSaveResult;
use App\Features\Events\Support\AdminEventInputPreparer;
use App\Features\Events\Support\AdminEventRelationsSynchronizer;
use App\Features\Events\Support\AdminEventScheduleResolver;
use App\Features\Events\Support\AdminEventTimeMapper;
use App\Features\Events\Support\EventModel;
use App\Shared\Events\Enums\TimingMode;
use App\Shared\Prayer\PrayerOffset;

/**
 * Persists one admin/advanced event save without moderation.
 *
 * Moderation stays in the root SaveAdminEventAction adapter until the
 * lifecycle slice moves it; this action owns the event, schedule,
 * classification, key-person and relation writes.
 */
final readonly class PersistEventAuthoringAction
{
    public function __construct(
        private AdminEventInputPreparer $inputPreparer,
        private AdminEventScheduleResolver $scheduleResolver,
        private AdminEventRelationsSynchronizer $relationsSynchronizer,
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

        app(SyncEventScheduleAction::class)->execute(
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

        $event->setPrimaryOrganizer($input['organizer_id'] !== null ? $input['primary_organizer'] : null);

        $this->relationsSynchronizer->sync($event, $state, $data, $creating);

        return new AdminEventSaveResult(
            eventId: (string) $event->getKey(),
            requestedStatus: $requestedStatus,
            created: $creating,
        );
    }
}
