<?php

namespace App\Actions\Events\Authoring;

use App\Actions\Events\Scheduling\SyncEventScheduleAction;
use App\Enums\PrayerOffset;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\User;
use App\Services\ModerationService;
use App\Support\Events\AdminEventInputPreparer;
use App\Support\Events\AdminEventRelationsSynchronizer;
use App\Support\Events\AdminEventScheduleResolver;
use App\Support\Events\AdminEventTimeMapper;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveAdminEventAction
{
    use AsAction;

    public function __construct(
        private AdminEventInputPreparer $inputPreparer,
        private AdminEventScheduleResolver $scheduleResolver,
        private AdminEventRelationsSynchronizer $relationsSynchronizer,
        private ModerationService $moderationService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?Event $event = null): Event
    {
        $creating = ! $event instanceof Event;
        $event ??= new Event;

        $input = $this->inputPreparer->prepare($data, $event, $creating);
        $state = $input['state'];
        $preparedSchedule = $this->scheduleResolver->resolve($data, $input, $event, $creating);
        $requestedStatus = $this->inputPreparer->requestedStatus($state, $event, $creating);
        $schedule = $preparedSchedule['schedule'];
        $attributes = $this->inputPreparer->attributes($input, $event, $creating, $schedule['timezone'], $schedule['starts_at']);

        if ($creating) {
            $event = Event::query()->create($attributes);
        } else {
            $event->fill($attributes);
            $event->save();
        }

        $event->syncLocation($input['venue_id'], $input['space_ids']);

        app(SyncEventScheduleAction::class)->execute($event, $preparedSchedule['schedule_kind'],
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

        if ($creating) {
            if ($requestedStatus === 'pending') {
                $this->moderationService->submitForModeration($event);
            }

            if ($requestedStatus === 'approved') {
                $this->moderationService->submitForModeration($event);
                $this->moderationService->approve($event, $actor);
            }
        }

        return $event->fresh([
            'accessPolicy',
            'references',
            'series',
            'classifications',
            'keyPeople',
            'languages',
            'media',
            'institution',
            'venue',
            'primaryLocation.venueSpace',
        ]) ?? $event;
    }
}
