<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use App\Features\Events\Actions\Submission\CompleteEventSubmissionWritesAction;
use App\Features\Events\Actions\Submission\PersistNewEventSubmissionAction;
use App\Features\Events\Actions\Submission\PersistSessionSubmissionAction;
use App\Features\Events\Actions\Submission\SubmissionRelationSync;
use App\Features\Events\Actions\Submission\ValidateEventSubmissionInputAction;
use App\Features\Events\Api\EventSubmissions;
use App\Shared\Events\ValidatedEventSubmission;

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
        private PersistNewEventSubmissionAction $persistNewEvent,
        private PersistSessionSubmissionAction $persistSession,
        private CompleteEventSubmissionWritesAction $completeWrites,
        private SubmissionRelationSync $relationSync,
    ) {}

    public function validate(array $state, string $prefix = ''): array
    {
        return $this->validateInput->handle($state, $prefix);
    }

    public function assertCatalogSelectionsAreAvailable(array $state, string $prefix = ''): void
    {
        $this->relationSync->assertCatalogSelectionsAreAvailable($state, $prefix);
    }

    public function persistNew(ValidatedEventSubmission $input, ?callable $persistMedia = null): array
    {
        $persisted = $this->persistNewEvent->handle($input, $persistMedia);

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

    public function persistSession(ValidatedEventSubmission $input, ?callable $persistMedia = null): array
    {
        $persisted = $this->persistSession->handle($input, $persistMedia);
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
     * @return array{event_id: string, submission_id: string|null, occurrence_id: string|null, session_id: string|null, parent_created: bool, grouped: bool, occurrence_pending_review: bool}
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
}
