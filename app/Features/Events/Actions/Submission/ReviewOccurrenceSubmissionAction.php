<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use AIArmada\Events\Models\EventSubmissionLog;
use AIArmada\Events\States\EventModerationStatus\Approved as SubmissionApproved;
use AIArmada\Events\States\EventModerationStatus\Pending as SubmissionPending;
use AIArmada\Events\States\EventModerationStatus\Rejected as SubmissionRejected;
use AIArmada\Events\States\OccurrenceStatus\Archived;
use AIArmada\Events\States\OccurrenceStatus\Scheduled;
use App\Features\Events\Support\EventModel;
use App\Features\Events\Models\Event;
use Illuminate\Validation\ValidationException;

/**
 * Apply moderation transitions and record the review for a pending occurrence submission.
 *
 * Callers keep these writes inside their transaction so related effects can
 * commit or roll back with the moderation decision.
 */
final readonly class ReviewOccurrenceSubmissionAction
{
    /**
     * @return array{event_id: string, submission_id: string, occurrence_id: string}
     */
    public function approve(
        string $submissionId,
        string $performedByType,
        string $performedById,
        ?string $note = null,
    ): array {
        [$submission, $event, $occurrence] = $this->lockedSubmission($submissionId);
        $this->assertPendingForApprovedParent($submission, $event, $occurrence);

        if ((string) $occurrence->status !== EventOccurrence::DRAFT) {
            throw ValidationException::withMessages([
                'submission' => __('This occurrence is no longer awaiting review.'),
            ]);
        }

        $requestedVisibility = data_get(
            $occurrence->metadata,
            'public_submission.requested_visibility',
            $event->visibility instanceof \BackedEnum ? $event->visibility->value : (string) $event->visibility,
        );
        $visibility = in_array($requestedVisibility, [Event::PUBLIC, Event::UNLISTED, Event::PRIVATE], true)
            ? $requestedVisibility
            : Event::PUBLIC;

        $occurrence->status->transitionTo(Scheduled::class);
        $occurrence->forceFill(['visibility' => $visibility])->save();
        $submission->status->transitionTo(SubmissionApproved::class);
        $submission->forceFill([
            'reviewed_at' => now(),
            'review_notes' => $note,
        ])->save();

        $this->recordReview(
            $submission,
            $event,
            $occurrence,
            $performedByType,
            $performedById,
            'occurrence_approved',
            $note,
        );

        return $this->result($submission, $event, $occurrence);
    }

    /**
     * @return array{event_id: string, submission_id: string, occurrence_id: string}
     */
    public function reject(
        string $submissionId,
        string $performedByType,
        string $performedById,
        string $reason,
        string $note,
    ): array {
        [$submission, $event, $occurrence] = $this->lockedSubmission($submissionId);
        $this->assertPendingForApprovedParent($submission, $event, $occurrence);

        if ((string) $occurrence->status !== EventOccurrence::DRAFT) {
            throw ValidationException::withMessages([
                'submission' => __('This occurrence is no longer awaiting review.'),
            ]);
        }

        $occurrence->status->transitionTo(Archived::class);
        $occurrence->forceFill(['status_reason' => $note])->save();
        $submission->status->transitionTo(SubmissionRejected::class);
        $submission->forceFill([
            'reviewed_at' => now(),
            'review_reason' => $reason,
            'review_notes' => $note,
        ])->save();

        $this->recordReview(
            $submission,
            $event,
            $occurrence,
            $performedByType,
            $performedById,
            'occurrence_rejected',
            $note,
            $reason,
        );

        return $this->result($submission, $event, $occurrence);
    }

    /**
     * @return array{PackageEventSubmission, Event, EventOccurrence}
     */
    private function lockedSubmission(string $submissionId): array
    {
        /** @var PackageEventSubmission|null $lockedSubmission */
        $lockedSubmission = EventModel::query('event_submission')
            ->whereKey($submissionId)
            ->lockForUpdate()
            ->first();

        if (! $lockedSubmission instanceof PackageEventSubmission) {
            throw ValidationException::withMessages([
                'submission' => __('This submission is no longer available.'),
            ]);
        }

        /** @var Event|null $event */
        $event = EventModel::query('event')
            ->whereKey($lockedSubmission->event_id)
            ->lockForUpdate()
            ->first();
        /** @var EventOccurrence|null $occurrence */
        $occurrence = EventModel::query('event_occurrence')
            ->whereKey($lockedSubmission->event_occurrence_id)
            ->lockForUpdate()
            ->first();

        if (! $event instanceof Event || ! $occurrence instanceof EventOccurrence) {
            throw ValidationException::withMessages([
                'submission' => __('The event occurrence for this submission is no longer available.'),
            ]);
        }

        return [$lockedSubmission, $event, $occurrence];
    }

    private function assertPendingForApprovedParent(
        PackageEventSubmission $submission,
        Event $event,
        EventOccurrence $occurrence,
    ): void {
        if (
            (string) $submission->status !== SubmissionPending::name()
            || $submission->event_session_id !== null
            || (string) $occurrence->event_id !== (string) $event->getKey()
            || (string) $event->status !== 'approved'
        ) {
            throw ValidationException::withMessages([
                'submission' => __('This submission is not awaiting occurrence review.'),
            ]);
        }
    }

    private function recordReview(
        PackageEventSubmission $submission,
        Event $event,
        EventOccurrence $occurrence,
        string $performedByType,
        string $performedById,
        string $action,
        ?string $note,
        ?string $reason = null,
    ): void {
        OwnerContext::withOwner(null, fn (): EventSubmissionLog => EventSubmissionLog::query()->create([
            'event_submission_id' => $submission->getKey(),
            'action' => $action,
            'performed_by_type' => $performedByType,
            'performed_by_id' => $performedById,
            'notes' => $note,
            'metadata' => array_filter([
                'event_id' => (string) $event->getKey(),
                'event_occurrence_id' => (string) $occurrence->getKey(),
                'reason_code' => $reason,
            ], static fn (mixed $value): bool => $value !== null),
        ]));
    }

    /**
     * @return array{event_id: string, submission_id: string, occurrence_id: string}
     */
    private function result(PackageEventSubmission $submission, Event $event, EventOccurrence $occurrence): array
    {
        return [
            'event_id' => (string) $event->getKey(),
            'submission_id' => (string) $submission->getKey(),
            'occurrence_id' => (string) $occurrence->getKey(),
        ];
    }
}
