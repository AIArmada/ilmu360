<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Actions\CreateEventSessionAction;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\EventKeyPersonSyncService;
use Illuminate\Validation\ValidationException;

final readonly class PersistValidatedEventSubmissionAction
{
    public function __construct(
        private CreateEventSessionAction $createEventSession,
        private GenerateEventSlugAction $generateEventSlug,
        private EventKeyPersonSyncService $eventKeyPersonSync,
        private SyncEventClassificationsAction $syncClassifications,
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
            'starts_at' => $submission->startsAt,
            'ends_at' => $submission->endsAt,
            'institution_id' => $submission->targetInstitutionId,
            'venue_id' => $submission->targetVenueId,
            'space_id' => $state['space_id'] ?? null,
            'gender' => $state['gender'] ?? EventGenderRestriction::All->value,
            'age_group' => $state['age_group'] ?? [EventAgeGroup::AllAges->value],
            'children_allowed' => $state['children_allowed'] ?? true,
            'is_muslim_only' => $state['is_muslim_only'] ?? false,
            'timing_mode' => $submission->prayerTime?->isCustomTime() ? 'absolute' : 'prayer_relative',
            'prayer_reference' => $submission->prayerReference,
            'prayer_offset' => $submission->prayerOffset,
            'prayer_display_text' => $submission->prayerDisplayText,
            'event_format' => $state['event_format'] ?? EventFormat::Physical->value,
            'event_url' => $state['event_url'] ?? null,
            'live_url' => $state['live_url'] ?? null,
            'visibility' => $state['visibility'] ?? EventVisibility::Public->value,
            'submitter_id' => $submission->submitter?->getKey(),
        ], $submission->autoApproved ? ['status' => 'pending'] : []));

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

        if (! empty($state['space_id']) && ! empty($event->institution_id)) {
            $institution = Institution::query()->find($event->institution_id);

            if ($institution instanceof Institution && ! $institution->spaces()->where('spaces.id', $state['space_id'])->exists()) {
                $institution->spaces()->attach($state['space_id']);
            }
        }

        $this->eventKeyPersonSync->sync($event, $state['speakers'] ?? [], $state['other_key_people'] ?? []);

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
            'submitter_type' => $submission->submitter instanceof User ? User::class : null,
            'submitter_id' => $submission->submitter?->getKey(),
        ]);

        return ['event' => $event, 'session' => $session, 'submission' => $eventSubmission];
    }
}
