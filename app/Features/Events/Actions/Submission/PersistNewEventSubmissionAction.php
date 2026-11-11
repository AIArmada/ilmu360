<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use App\Features\Events\Actions\Scheduling\ResolveInstitutionalPrayerSlotGroupAction;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Support\EventModel;
use App\Shared\Events\Enums\EventVisibility;
use App\Shared\Events\ValidatedEventSubmission;
use Illuminate\Support\Arr;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

final readonly class PersistNewEventSubmissionAction
{
    public function __construct(
        private EventAuthoring $authoring,
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
     * @return array{event: PackageEvent, session: null, occurrence: EventOccurrence, submission: PackageEventSubmission, grouped: bool, parent_created: bool, occurrence_pending_review: bool}
     */
    public function handle(ValidatedEventSubmission $submission, ?callable $persistRelationships = null): array
    {
        $groupIdentity = $this->groupResolver->identity($submission);
        $event = $groupIdentity === null ? null : $this->groupResolver->findExisting($groupIdentity);
        $parentCreated = ! $event instanceof PackageEvent;

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

        $eventSubmission = EventModel::query('event_submission')->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'event_session_id' => null,
            'status' => 'pending',
            'submitted_at' => now(),
            'submission_data' => $submissionData,
            'submitter_type' => $submission->submitter?->getMorphClass(),
            'submitter_id' => $submission->submitter?->getKey(),
        ]);

        if (! $eventSubmission instanceof PackageEventSubmission) {
            throw new RuntimeException('Expected the event submission model to extend the events package model.');
        }

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
     * @param  array{key: string, institution_id: string, category_ids: list<string>, prayer_slot: string, title: string, slug_title: string}|null  $groupIdentity
     */
    private function createParent(ValidatedEventSubmission $submission, ?array $groupIdentity): PackageEvent
    {
        $state = $submission->state;
        $title = $groupIdentity['title'] ?? (string) $state['title'];
        $metadata = $groupIdentity === null
            ? null
            : ['public_submission_group' => Arr::only($groupIdentity, ['key', 'institution_id', 'category_ids', 'prayer_slot'])];

        $event = EventModel::query('event')->create(array_merge([
            'title' => $title,
            'slug' => $this->authoring->eventSlug(
                $groupIdentity['slug_title'] ?? $title,
                null,
                $submission->timezone,
            ),
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

        if (! $event instanceof PackageEvent) {
            throw new RuntimeException('Expected the event model to extend the events package model.');
        }

        $primaryOrganizer = $groupIdentity === null
            ? $submission->primaryOrganizer
            : EventModel::query('institution')->find($groupIdentity['institution_id']) ?? $submission->primaryOrganizer;

        $this->authoring->syncPrimaryOrganizer(
            (string) $event->getKey(),
            $primaryOrganizer->getMorphClass(),
            (string) $primaryOrganizer->getKey(),
        );
        $event->unsetRelation('primaryOrganizerInvolvement');

        if ($groupIdentity !== null) {
            $this->authoring->syncEventClassifications($event->getMorphClass(), (string) $event->getKey(), [
                'event_category_ids' => $groupIdentity['category_ids'],
            ]);
        } else {
            $this->authoring->syncEventClassifications($event->getMorphClass(), (string) $event->getKey(), $submission->state);
        }

        return $event;
    }

    /**
     * @param  array{key: string, institution_id: string, category_ids: list<string>, prayer_slot: string, title: string, slug_title: string}  $groupIdentity
     */
    private function storeGroupIdentity(PackageEvent $event, array $groupIdentity): void
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
