<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Data\ScheduleKind;
use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Catalog\States\OccurrenceStatus\Archived;
use AIArmada\Events\Features\Catalog\States\OccurrenceStatus\Scheduled;
use AIArmada\Events\Features\Governance\Models\EventSubmission as PackageEventSubmission;
use AIArmada\Events\Features\Governance\Models\EventSubmissionLog;
use AIArmada\Events\Features\Governance\States\EventModerationStatus\Approved as SubmissionApproved;
use AIArmada\Events\Features\Governance\States\EventModerationStatus\Pending as SubmissionPending;
use AIArmada\Events\Features\Governance\States\EventModerationStatus\Rejected as SubmissionRejected;
use App\Features\Events\Actions\Schedule\SaveOccurrenceAction;
use App\Features\Events\Actions\Schedule\SaveSessionAction;
use App\Features\Events\Models\Event;
use App\Features\Events\Support\EventModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Apply moderation transitions and record the review for a pending occurrence submission.
 *
 * Callers keep these writes inside their transaction so related effects can
 * commit or roll back with the moderation decision.
 */
final readonly class ReviewOccurrenceSubmissionAction
{
    public function __construct(
        private SaveOccurrenceAction $saveOccurrence,
        private SaveSessionAction $saveSession,
    ) {}

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

        if ($submission->submitter_type === $performedByType
            && (string) $submission->submitter_id === $performedById) {
            throw new AuthorizationException(__('A different reviewer must approve this update.'));
        }

        $change = data_get($submission->submission_data, 'schedule_change');

        if (is_array($change)) {
            $this->applyScheduleChange($event, $occurrence, $change);
        } else {
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

            if ($event->occurrences()->whereState('status', Scheduled::class)->count() > 1) {
                $event->forceFill(['schedule_kind' => ScheduleKind::CustomChain])->save();
            }
        }

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

        if (! is_array(data_get($submission->submission_data, 'schedule_change'))) {
            if ((string) $occurrence->status !== EventOccurrence::DRAFT) {
                throw ValidationException::withMessages([
                    'submission' => __('This occurrence is no longer awaiting review.'),
                ]);
            }

            $occurrence->status->transitionTo(Archived::class);
            $occurrence->forceFill(['status_reason' => $note])->save();
        }

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

    /** @param array<string, mixed> $change */
    private function applyScheduleChange(Event $event, EventOccurrence $occurrence, array $change): void
    {
        $values = $change['values'] ?? null;
        $kind = $change['kind'] ?? null;
        $sessionId = $change['session_id'] ?? null;

        if (! is_array($values) || ! in_array($kind, ['occurrence', 'session'], true)
            || (string) $occurrence->status !== EventOccurrence::SCHEDULED) {
            throw ValidationException::withMessages(['submission' => __('This schedule change cannot be applied.')]);
        }

        $target = $kind === 'occurrence' ? $occurrence : null;

        if ($kind === 'session' && is_string($sessionId)) {
            $target = $occurrence->sessions()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
        }

        if ($target !== null && Arr::only($target->getRawOriginal(), array_keys($values)) != ($change['original'] ?? null)) {
            throw ValidationException::withMessages([
                'submission' => __('The schedule changed after this request was submitted. Ask the contributor to submit it again.'),
            ]);
        }

        if ($kind === 'occurrence') {
            $this->saveOccurrence->handle($event, $values, (string) $occurrence->getKey());

            return;
        }

        if ($sessionId === null) {
            $values['visibility'] = (string) $occurrence->visibility;
            $values['delivery_mode'] = (string) $occurrence->delivery_mode;
        }

        $this->saveSession->handle($event, (string) $occurrence->getKey(), $values, is_string($sessionId) ? $sessionId : null);
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
