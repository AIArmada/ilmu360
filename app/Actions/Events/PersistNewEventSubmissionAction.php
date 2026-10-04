<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Support\Normalization\EventContentNormalizer;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Services\EventKeyPersonSyncService;
use App\Support\Events\AdminEventTimeMapper;
use App\Support\Submission\SubmissionRelationSync;
use Spatie\MediaLibrary\HasMedia;

final readonly class PersistNewEventSubmissionAction
{
    public function __construct(
        private GenerateEventSlugAction $generateEventSlug,
        private EventKeyPersonSyncService $eventKeyPersonSync,
        private SyncEventClassificationsAction $syncClassifications,
        private SyncEventScheduleAction $syncSchedule,
        private EventContentNormalizer $contentNormalizer,
        private SubmissionRelationSync $relationSync,
    ) {}

    /**
     * @param  (callable(HasMedia): void)|null  $persistRelationships
     * @return array{event: Event, session: null, submission: EventSubmission}
     */
    public function handle(ValidatedEventSubmission $submission, ?callable $persistRelationships = null): array
    {
        $state = $submission->state;
        $spaceIds = is_array($state['space_ids'] ?? null) ? array_values(array_filter($state['space_ids'], is_string(...))) : [];

        $event = Event::query()->create(array_merge([
            'title' => $state['title'],
            'slug' => $this->generateEventSlug->handle(
                (string) $state['title'],
                $state['event_date'] ?? null,
                $submission->timezone,
                null,
                $submission->personSlugSegments,
            ),
            'description' => $this->eventDescription($state['description'] ?? null),
            'timezone' => $submission->timezone,
            'institution_id' => $submission->targetInstitutionId,
            'default_venue_id' => $submission->targetVenueId,
            'gender' => $state['gender'] ?? EventGenderRestriction::All->value,
            'age_group' => $state['age_group'] ?? [EventAgeGroup::AllAges->value],
            'children_allowed' => $state['children_allowed'] ?? true,
            'is_muslim_only' => $state['is_muslim_only'] ?? false,
            'delivery_mode' => $state['event_format'] ?? EventFormat::Physical->value,
            'event_url' => $state['event_url'] ?? null,
            'live_url' => $state['live_url'] ?? null,
            'visibility' => $state['visibility'] ?? EventVisibility::Public->value,
        ], $submission->autoApproved ? ['status' => 'pending'] : []));

        $event->syncLocation(
            $submission->targetVenueId,
            $spaceIds,
        );

        $this->syncSchedule->execute(
            event: $event,
            scheduleKind: ScheduleKind::Single,
            startsAt: $submission->startsAt,
            endsAt: $submission->endsAt,
            timezone: $submission->timezone,
            timingMode: $submission->prayerTime instanceof EventPrayerTime
                && ! $submission->prayerTime->isCustomTime()
                ? TimingMode::PrayerRelative
                : TimingMode::Absolute,
            prayerReference: $submission->prayerReference,
            prayerOffset: $submission->prayerOffset,
            prayerDisplayText: $submission->prayerDisplayText,
            prayerSource: $submission->prayerSource,
            prayerFetchedAt: $submission->prayerFetchedAt,
            prayerZone: $submission->prayerZone,
            prayerDate: AdminEventTimeMapper::normalizeEventDateString($state['event_date'] ?? null, $submission->timezone),
            prayerLat: $submission->prayerLat,
            prayerLng: $submission->prayerLng,
            prayerVenueId: $submission->targetVenueId,
            prayerInstitutionId: $submission->targetInstitutionId,
            prayerCountry: $submission->prayerCountry,
        );

        $event->setPrimaryOrganizer($submission->primaryOrganizer);

        $this->eventKeyPersonSync->sync($event, $this->relationSync->normalizePersonIds($state['persons'] ?? []), $this->relationSync->canonicalKeyPeople($state['other_key_people'] ?? []));

        if (! empty($state['languages'])) {
            $event->syncLanguages($state['languages']);
        }

        $this->syncClassifications->handle($event, $state);
        $this->relationSync->syncEventReferences($event, $state['references'] ?? null);

        if ($persistRelationships !== null) {
            $persistRelationships($event);
        }

        $submissionData = ['submitter_name' => $state['submitter_name'] ?? $submission->submitter?->name];

        if (isset($state['notes'])) {
            $submissionData['notes'] = $state['notes'];
        }

        $eventSubmission = EventSubmission::query()->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => null,
            'event_session_id' => null,
            'status' => 'pending',
            'submitted_at' => now(),
            'submission_data' => $submissionData,
            'submitter_type' => $submission->submitter?->getMorphClass(),
            'submitter_id' => $submission->submitter?->getKey(),
        ]);

        return ['event' => $event, 'session' => null, 'submission' => $eventSubmission];
    }

    /**
     * Event descriptions persist in the model's array-cast shape: a validated
     * localized map is stored as-is, scalar strings go through the content
     * normalizer, and anything else stays null.
     *
     * @return array<string, string|null>|string|null
     */
    private function eventDescription(mixed $description): array|string|null
    {
        if (is_array($description)) {
            return $description === [] ? null : $description;
        }

        if (is_string($description)) {
            return $this->contentNormalizer->normalizeDescription($description);
        }

        return null;
    }
}
