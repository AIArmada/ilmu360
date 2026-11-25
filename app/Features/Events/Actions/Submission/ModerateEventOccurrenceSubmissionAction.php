<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use AIArmada\Events\Features\Governance\States\EventModerationStatus\Approved as SubmissionApproved;
use AIArmada\Events\Features\Governance\States\EventModerationStatus\Pending as SubmissionPending;
use AIArmada\Events\Features\Governance\States\EventModerationStatus\Rejected as SubmissionRejected;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Api\OccurrenceSubmissionReviews;
use App\Features\Events\Contracts\OccurrenceReviewNotifier;
use App\Features\ShareTracking\Api\ShareOutcomeBusinessKey;
use App\Features\ShareTracking\Api\ShareOutcomeReversals;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Owns the atomic occurrence review and the post-transaction signals and
 * notification. Package submission transitions remain in EventSubmissions.
 */
final readonly class ModerateEventOccurrenceSubmissionAction implements OccurrenceSubmissionReviews
{
    public function __construct(
        private ShareOutcomeReversals $reverseShareOutcome,
        private SignalRecorder $signals,
        private EventSubmissions $eventSubmissions,
        private OccurrenceReviewNotifier $notifier,
    ) {}

    public function approve(string $submissionId, string $moderatorType, string $moderatorId, ?string $note = null): void
    {
        $result = DB::transaction(fn (): array => $this->eventSubmissions->approveOccurrenceSubmission(
            $submissionId,
            $moderatorType,
            $moderatorId,
            $note,
        ));

        $this->recordOccurrenceModerationSignal(
            $result,
            'approved',
            $moderatorId,
            SubmissionPending::name(),
            SubmissionApproved::name(),
            note: $note,
        );

        $this->notifier->notify($result['submission_id'], $result['event_id'], $result['occurrence_id'], true, null, $note);
    }

    public function reject(string $submissionId, string $moderatorType, string $moderatorId, string $reason, string $note): void
    {
        $result = DB::transaction(function () use ($submissionId, $moderatorType, $moderatorId, $reason, $note): array {
            $result = $this->eventSubmissions->rejectOccurrenceSubmission(
                $submissionId,
                $moderatorType,
                $moderatorId,
                $reason,
                $note,
            );

            $this->reverseShareOutcome->reverse(
                ShareOutcomeBusinessKey::eventSubmission($result['submission_id']),
                'submission_rejected',
                $moderatorId,
            );

            return $result;
        });

        $this->recordOccurrenceModerationSignal(
            $result,
            'rejected',
            $moderatorId,
            SubmissionPending::name(),
            SubmissionRejected::name(),
            reasonCode: $reason,
            note: $note,
        );

        $this->notifier->notify($result['submission_id'], $result['event_id'], $result['occurrence_id'], false, $reason, $note);
    }

    /**
     * @param  array{event_id: string, submission_id: string, occurrence_id: string}  $result
     */
    private function recordOccurrenceModerationSignal(
        array $result,
        string $action,
        string $moderatorId,
        string $previousStatus,
        string $currentStatus,
        ?string $reasonCode = null,
        ?string $note = null,
    ): void {
        $this->signals->record(
            eventName: 'moderation.event.occurrence_'.$action,
            eventCategory: 'moderation',
            actorId: $moderatorId,
            properties: [
                'event_id' => $result['event_id'],
                'event_occurrence_id' => $result['occurrence_id'],
                'submission_id' => $result['submission_id'],
                'moderation_action' => $action,
                'previous_status' => $previousStatus,
                'current_status' => $currentStatus,
                'moderator_id' => $moderatorId,
                'reason_code' => $reasonCode,
                'has_note' => filled($note),
            ],
            request: request(),
        );
    }
}
