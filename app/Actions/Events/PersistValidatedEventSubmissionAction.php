<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Actions\CreateEventSessionAction;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Services\EventKeyPersonSyncService;
use Illuminate\Validation\ValidationException;

final readonly class PersistValidatedEventSubmissionAction
{
    public function __construct(
        private CreateEventSessionAction $createEventSession,
        private GenerateEventSlugAction $generateEventSlug,
        private EventKeyPersonSyncService $eventKeyPersonSync,
        private SyncEventClassificationsAction $syncClassifications,
        private SyncEventScheduleAction $syncSchedule,
    ) {}

    /**
     * @param  (callable(Event): void)|null  $persistRelationships
     * @return array{event: Event, session: EventSession|null, submission: EventSubmission}
     */
    public function handle(ValidatedEventSubmission $submission, ?callable $persistRelationships = null): array
    {
        $state = $submission->state;
        $event = $submission->eventContainer ?? Event::query()->create(array_merge([
            'title' => $state['title'],
            'slug' => $this->generateEventSlug->handle(
                (string) $state['title'],
                $state['event_date'] ?? null,
                $submission->timezone,
                null,
                $submission->speakerSlugSegments,
            ),
            'description' => $state['description'] ?? null,
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

        if (! $submission->eventContainer instanceof Event) {
            $event->syncLocation(
                $submission->targetVenueId,
                is_array($state['space_ids'] ?? null) ? array_values(array_filter($state['space_ids'], is_string(...))) : [],
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
            );
        }

        $session = null;

        if ($submission->sessionSubmission) {
            $occurrence = $event->primaryOccurrence;

            if (! $occurrence instanceof EventOccurrence) {
                throw ValidationException::withMessages([
                    'event_id' => __('The selected event has no occurrence for this session.'),
                ]);
            }

            $session = $this->createEventSession->handle($occurrence, [
                'title' => $state['title'],
                'slug' => $this->generateEventSlug->handle(
                    (string) $state['title'],
                    $state['event_date'] ?? null,
                    $submission->timezone,
                    null,
                    $submission->speakerSlugSegments,
                ),
                'summary' => $state['description'] ?? null,
                'description' => $state['description'] ?? null,
                'starts_at' => $submission->startsAt,
                'ends_at' => $submission->endsAt,
                'timezone' => $submission->timezone,
                'visibility' => $state['visibility'] ?? $event->visibility,
                'delivery_mode' => $event->delivery_mode,
            ]);
        }

        $event->setPrimaryOrganizer($submission->primaryOrganizer);

        $spaceIds = is_array($state['space_ids'] ?? null) ? array_values(array_filter($state['space_ids'], is_string(...))) : [];

        if ($spaceIds !== [] && ! empty($event->institution_id)) {
            $institution = Institution::query()->find($event->institution_id);

            if ($institution instanceof Institution) {
                $validIds = $institution->spaces()->pluck('spaces.id')->map(strval(...))->all();
                $invalidIds = array_diff($spaceIds, $validIds);

                if ($invalidIds !== []) {
                    throw ValidationException::withMessages([
                        'space_ids' => __('Ruang yang dipilih tidak tersedia untuk institusi ini.'),
                    ]);
                }
            }
        }

        $this->eventKeyPersonSync->sync($event, $state['speakers'] ?? [], $this->canonicalKeyPeople($state['other_key_people'] ?? []));

        if (! empty($state['languages'])) {
            $event->syncLanguages($state['languages']);
        }

        $this->syncClassifications->handle($event, $state);
        if ($persistRelationships !== null) {
            $persistRelationships($event);
        }

        $submissionData = ['submitter_name' => $state['submitter_name'] ?? $submission->submitter?->name];

        if (isset($state['notes'])) {
            $submissionData['notes'] = $state['notes'];
        }

        $eventSubmission = EventSubmission::query()->create([
            'event_id' => $event->getKey(),
            'status' => 'pending',
            'submitted_at' => now(),
            'submission_data' => $submissionData,
            'submitter_type' => $submission->submitter?->getMorphClass(),
            'submitter_id' => $submission->submitter?->getKey(),
        ]);

        return ['event' => $event, 'session' => $session, 'submission' => $eventSubmission];
    }

    /** @return list<array<string, mixed>> */
    private function canonicalKeyPeople(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)->filter(fn (mixed $row): bool => is_array($row))->map(static fn (array $row): array => [
            'role_code' => $row['role_code'] ?? null,
            'involveable_type' => $row['involveable_type'] ?? null,
            'involveable_id' => $row['involveable_id'] ?? null,
            'display_name' => $row['display_name'] ?? null,
            'visibility' => $row['visibility'] ?? 'public',
            'notes' => $row['notes'] ?? null,
        ])->values()->all();
    }
}
