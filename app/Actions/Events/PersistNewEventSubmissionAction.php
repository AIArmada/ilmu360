<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventOccurrence;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use Illuminate\Support\Arr;
use Spatie\MediaLibrary\HasMedia;

final readonly class PersistNewEventSubmissionAction
{
    public function __construct(
        private GenerateEventSlugAction $generateEventSlug,
        private SyncEventClassificationsAction $syncClassifications,
        private ResolveInstitutionalPrayerSlotGroupAction $groupResolver,
        private PersistOccurrenceSubmissionAction $persistOccurrence,
    ) {}

    /**
     * Persist one ordinary public submission as a date-free parent plus an
     * immutable occurrence. Institutional prayer-slot matches append to the
     * existing parent; explicit event-container submissions use the separate
     * session action.
     *
     * @param  (callable(HasMedia): void)|null  $persistRelationships
     * @return array{event: Event, session: null, occurrence: EventOccurrence, submission: EventSubmission, grouped: bool, parent_created: bool, occurrence_pending_review: bool}
     */
    public function handle(ValidatedEventSubmission $submission, ?callable $persistRelationships = null): array
    {
        $groupIdentity = $this->groupResolver->identity($submission);
        $event = $groupIdentity === null ? null : $this->groupResolver->findExisting($groupIdentity);
        $parentCreated = ! $event instanceof Event;

        if ($parentCreated) {
            $event = $this->createParent($submission, $groupIdentity);
        } elseif ($groupIdentity !== null) {
            $this->storeGroupIdentity($event, $groupIdentity);
        }

        $occurrencePendingReview = ! $parentCreated
            && ! $submission->autoApproved
            && (string) $event->status === 'approved';

        $occurrence = $this->persistOccurrence->handle(
            event: $event,
            submission: $submission,
            pendingReview: $occurrencePendingReview,
            groupIdentity: $groupIdentity,
            persistRelationships: $persistRelationships,
        );

        $event->forceFill([
            'schedule_kind' => $event->occurrences()->count() > 1
                ? ScheduleKind::CustomChain
                : ScheduleKind::Single,
        ])->save();

        $submissionData = [
            'submitter_name' => $submission->state['submitter_name'] ?? $submission->submitter?->name,
            'occurrence_title' => $occurrence->title,
        ];

        if ($groupIdentity !== null) {
            $submissionData['submission_group_key'] = $groupIdentity['key'];
        }

        if (isset($submission->state['notes'])) {
            $submissionData['notes'] = $submission->state['notes'];
        }

        $eventSubmission = EventSubmission::query()->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'event_session_id' => null,
            'status' => 'pending',
            'submitted_at' => now(),
            'submission_data' => $submissionData,
            'submitter_type' => $submission->submitter?->getMorphClass(),
            'submitter_id' => $submission->submitter?->getKey(),
        ]);

        return [
            'event' => $event,
            'session' => null,
            'occurrence' => $occurrence,
            'submission' => $eventSubmission,
            'grouped' => $groupIdentity !== null,
            'parent_created' => $parentCreated,
            'occurrence_pending_review' => $occurrencePendingReview,
        ];
    }

    /**
     * @param  array{key: string, institution_id: string, category_ids: list<string>, prayer_slot: string, title: string}|null  $groupIdentity
     */
    private function createParent(ValidatedEventSubmission $submission, ?array $groupIdentity): Event
    {
        $state = $submission->state;
        $title = $groupIdentity['title'] ?? (string) $state['title'];
        $metadata = $groupIdentity === null
            ? null
            : ['public_submission_group' => Arr::only($groupIdentity, ['key', 'institution_id', 'category_ids', 'prayer_slot'])];

        $event = Event::query()->create(array_merge([
            'title' => $title,
            'slug' => $this->generateEventSlug->handle($title, null, $submission->timezone),
            'description' => null,
            'summary' => null,
            'timezone' => $submission->timezone,
            'institution_id' => $submission->targetInstitutionId,
            'default_venue_id' => null,
            'event_url' => null,
            'live_url' => null,
            'visibility' => $state['visibility'] ?? EventVisibility::Public->value,
            'schedule_kind' => ScheduleKind::Single,
            'metadata' => $metadata,
        ], $submission->autoApproved ? ['status' => 'pending'] : []));

        $primaryOrganizer = $groupIdentity === null
            ? $submission->primaryOrganizer
            : Institution::query()->find($groupIdentity['institution_id']) ?? $submission->primaryOrganizer;
        $event->setPrimaryOrganizer($primaryOrganizer);

        if ($groupIdentity !== null) {
            $this->syncClassifications->handle($event, [
                'event_category_ids' => $groupIdentity['category_ids'],
            ]);
        } else {
            $this->syncClassifications->handle($event, $submission->state);
        }

        return $event;
    }

    /**
     * @param  array{key: string, institution_id: string, category_ids: list<string>, prayer_slot: string, title: string}  $groupIdentity
     */
    private function storeGroupIdentity(Event $event, array $groupIdentity): void
    {
        $metadata = $event->metadata ?? [];
        $metadata['public_submission_group'] = Arr::only($groupIdentity, [
            'key',
            'institution_id',
            'category_ids',
            'prayer_slot',
        ]);

        $event->forceFill(['metadata' => $metadata])->save();
    }
}
