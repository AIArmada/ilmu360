<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use App\Features\Events\Api\EventModeration;
use App\Features\Events\Api\EventSubmissionCompletion;
use App\Features\Events\Api\Orm\EventSubmissions;

/** Owner of completion writes and parent moderation decisions. */
final readonly class CompleteFrontendEventSubmissionAction implements EventSubmissionCompletion
{
    public function __construct(
        private EventModeration $moderation,
        private EventSubmissions $submissions,
    ) {}

    public function complete(
        string $eventId,
        string $submissionId,
        array $state,
        ?string $submitterId,
        bool $sessionSubmission,
        bool $autoApproved,
        bool $parentCreated,
    ): void {
        $moderationAction = $this->submissions->completeWrites(
            eventId: $eventId,
            submissionId: $submissionId,
            state: $state,
            storeSubmitterContacts: $submitterId === null,
            sessionSubmission: $sessionSubmission,
            parentCreated: $parentCreated,
            autoApproved: $autoApproved,
        );

        // Session and grouped-occurrence submissions do not change the
        // existing parent lifecycle, including its admission defaults.
        if ($sessionSubmission || ! $parentCreated) {
            return;
        }

        if ($moderationAction === 'approve') {
            $this->moderation->approve(
                $eventId,
                $submitterId,
                'Auto-approved from institution dashboard submission.',
            );
        } elseif ($moderationAction === 'submit') {
            $this->moderation->submitForModeration($eventId);
        }
    }
}
