<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use App\Features\Events\Actions\Submission\CompleteEventSubmissionWritesAction;
use App\Features\Events\Actions\Submission\PersistNewEventSubmissionAction;
use App\Features\Events\Actions\Submission\PersistSessionSubmissionAction;
use App\Features\Events\Actions\Submission\ResolveEventSubmissionPrayerStartAction;
use App\Features\Events\Actions\Submission\ReviewOccurrenceSubmissionAction;
use App\Features\Events\Actions\Submission\SubmissionRelationSync;
use App\Features\Events\Actions\Submission\ValidatedEventSubmission;
use App\Features\Events\Actions\Submission\ValidateEventSubmissionCategoriesAction;
use App\Features\Events\Actions\Submission\ValidateEventSubmissionInputAction;
use App\Features\Events\Api\EventSubmissionInput;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Support\EventModel;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Prayer\EventPrayerTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Submission persistence boundary implementation.
 *
 * Delegates validation to the structural guard and persistence to the named
 * submission actions, then reduces their model results to scalar identifiers
 * and flags so no Eloquent model crosses the Events public boundary.
 */
final readonly class EventSubmissionsService implements EventSubmissions
{
    public function __construct(
        private ValidateEventSubmissionInputAction $validateInput,
        private ValidateEventSubmissionCategoriesAction $categories,
        private ResolveEventSubmissionPrayerStartAction $prayerStart,
        private PersistNewEventSubmissionAction $persistNewEvent,
        private PersistSessionSubmissionAction $persistSession,
        private ReviewOccurrenceSubmissionAction $reviewOccurrenceSubmission,
        private CompleteEventSubmissionWritesAction $completeWrites,
        private SubmissionRelationSync $relationSync,
    ) {}

    public function validate(array $state, string $prefix = ''): array
    {
        return $this->validateInput->handle($state, $prefix);
    }

    public function normalizeCategories(array $state, string $prefix = ''): array
    {
        return $this->categories->normalize($state, $prefix);
    }

    public function resolvePrayerStartClock(
        ?string $institutionId,
        ?string $venueId,
        ?string $countryCode,
        string $timezone,
        string $prayerTime,
        string $eventDate,
    ): ?array {
        return $this->prayerStart->handle(
            $institutionId,
            $venueId,
            $countryCode,
            $timezone,
            $prayerTime,
            $eventDate,
        );
    }

    public function assertCommunityPhysicalFormat(array $state, string $prefix = ''): void
    {
        $this->categories->assertCommunityPhysicalFormat($state, $prefix);
    }

    public function assertCatalogSelectionsAreAvailable(array $state, string $prefix = ''): void
    {
        $this->relationSync->assertCatalogSelectionsAreAvailable($state, $prefix);
    }

    public function persistNew(EventSubmissionInput $input): array
    {
        $persisted = $this->persistNewEvent->handle($this->resolveInput($input));

        return $this->result(
            event: $persisted['event'],
            submission: $persisted['submission'],
            occurrence: $persisted['occurrence'],
            sessionId: null,
            parentCreated: $persisted['parent_created'],
            grouped: $persisted['grouped'],
            occurrencePendingReview: $persisted['occurrence_pending_review'],
        );
    }

    public function persistSession(EventSubmissionInput $input): array
    {
        $persisted = $this->persistSession->handle($this->resolveInput($input));
        $occurrence = $persisted['session']->occurrence;

        return $this->result(
            event: $persisted['event'],
            submission: $persisted['submission'],
            occurrence: $occurrence instanceof EventOccurrence ? $occurrence : null,
            sessionId: (string) $persisted['session']->getKey(),
            parentCreated: false,
            grouped: false,
            occurrencePendingReview: false,
        );
    }

    public function approveOccurrenceSubmission(
        string $submissionId,
        string $performedByType,
        string $performedById,
        ?string $note = null,
    ): array {
        return $this->reviewOccurrenceSubmission->approve(
            $submissionId,
            $performedByType,
            $performedById,
            $note,
        );
    }

    public function rejectOccurrenceSubmission(
        string $submissionId,
        string $performedByType,
        string $performedById,
        string $reason,
        string $note,
    ): array {
        return $this->reviewOccurrenceSubmission->reject(
            $submissionId,
            $performedByType,
            $performedById,
            $reason,
            $note,
        );
    }

    public function completeWrites(
        string $eventId,
        string $submissionId,
        array $state,
        bool $storeSubmitterContacts,
        bool $sessionSubmission,
        bool $parentCreated,
        bool $autoApproved,
    ): ?string {
        return $this->completeWrites->handle(
            eventId: $eventId,
            submissionId: $submissionId,
            state: $state,
            storeSubmitterContacts: $storeSubmitterContacts,
            sessionSubmission: $sessionSubmission,
            parentCreated: $parentCreated,
            autoApproved: $autoApproved,
        );
    }

    /**
     * @return array{event_id: string, submission_id: string, occurrence_id: string|null, session_id: string|null, parent_created: bool, grouped: bool, occurrence_pending_review: bool}
     */
    private function result(
        PackageEvent $event,
        PackageEventSubmission $submission,
        ?EventOccurrence $occurrence,
        ?string $sessionId,
        bool $parentCreated,
        bool $grouped,
        bool $occurrencePendingReview,
    ): array {
        return [
            'event_id' => (string) $event->getKey(),
            'submission_id' => (string) $submission->getKey(),
            'occurrence_id' => $occurrence instanceof EventOccurrence ? (string) $occurrence->getKey() : null,
            'session_id' => $sessionId,
            'parent_created' => $parentCreated,
            'grouped' => $grouped,
            'occurrence_pending_review' => $occurrencePendingReview,
        ];
    }

    private function resolveInput(EventSubmissionInput $input): ValidatedEventSubmission
    {
        $primaryOrganizer = $this->resolveModel($input->primaryOrganizerKind->value, $input->primaryOrganizerId);
        $submitter = $input->submitterId === null ? null : $this->resolveModel('user', $input->submitterId);
        $eventContainer = $input->eventContainerId === null
            ? null
            : EventModel::query('event')->find($input->eventContainerId);

        if ($eventContainer !== null && ! $eventContainer instanceof PackageEvent) {
            throw new \RuntimeException('Expected the event model to extend the events package model.');
        }

        return new ValidatedEventSubmission(
            state: $input->state,
            startsAt: Carbon::parse($input->startsAt),
            endsAt: $input->endsAt === null ? null : Carbon::parse($input->endsAt),
            timezone: $input->timezone,
            primaryOrganizer: $primaryOrganizer,
            targetInstitutionId: $input->targetInstitutionId,
            targetVenueId: $input->targetVenueId,
            prayerTime: $input->prayerTime === null ? null : EventPrayerTime::from($input->prayerTime),
            prayerReference: $input->prayerReference,
            prayerOffset: $input->prayerOffset,
            prayerDisplayText: $input->prayerDisplayText,
            autoApproved: $input->autoApproved,
            sessionSubmission: $input->sessionSubmission,
            submitter: $submitter,
            eventContainer: $eventContainer,
            personSlugSegments: $input->personSlugSegments,
            occurrenceId: $input->occurrenceId,
            validationKeyPrefix: $input->validationKeyPrefix,
            prayerSource: $input->prayerSource,
            prayerFetchedAt: $input->prayerFetchedAt,
            prayerZone: $input->prayerZone,
            prayerLat: $input->prayerLat,
            prayerLng: $input->prayerLng,
            prayerCountry: $input->prayerCountry,
        );
    }

    private function resolveModel(string $alias, string $id): Model
    {
        $modelClass = MorphModelResolver::resolve($alias);
        $model = $modelClass::query()->findOrFail($id);

        if (! $model instanceof Model) {
            throw new \RuntimeException("Expected the [{$alias}] morph alias to resolve to an Eloquent model.");
        }

        return $model;
    }
}
