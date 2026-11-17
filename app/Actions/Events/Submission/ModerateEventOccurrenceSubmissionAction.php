<?php

declare(strict_types=1);

namespace App\Actions\Events\Submission;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\States\EventModerationStatus\Approved as SubmissionApproved;
use AIArmada\Events\States\EventModerationStatus\Pending as SubmissionPending;
use AIArmada\Events\States\EventModerationStatus\Rejected as SubmissionRejected;
use App\Features\ShareTracking\Api\ShareOutcomeReversals;
use App\Features\Events\Api\EventSubmissions;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Notifications\EventOccurrenceReviewedNotification;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final readonly class ModerateEventOccurrenceSubmissionAction
{
    public function __construct(
        private ShareOutcomeReversals $reverseShareOutcome,
        private SignalRecorder $signals,
        private EventSubmissions $eventSubmissions,
    ) {}

    public function approve(EventSubmission $submission, User $moderator, ?string $note = null): void
    {
        [$submission, $event, $occurrence] = DB::transaction(function () use ($submission, $moderator, $note): array {
            $result = $this->eventSubmissions->approveOccurrenceSubmission(
                (string) $submission->getKey(),
                $moderator->getMorphClass(),
                (string) $moderator->getKey(),
                $note,
            );

            return $this->rehydrateModerationModels($result);
        });

        $this->recordOccurrenceModerationSignal(
            $event,
            $submission,
            $occurrence,
            'approved',
            $moderator,
            SubmissionPending::name(),
            SubmissionApproved::name(),
            note: $note,
        );

        $this->notifySubmitter($submission, $event, $occurrence, approved: true, reason: null, note: $note);
    }

    public function reject(EventSubmission $submission, User $moderator, string $reason, string $note): void
    {
        [$submission, $event, $occurrence] = DB::transaction(function () use ($submission, $moderator, $reason, $note): array {
            $result = $this->eventSubmissions->rejectOccurrenceSubmission(
                (string) $submission->getKey(),
                $moderator->getMorphClass(),
                (string) $moderator->getKey(),
                $reason,
                $note,
            );

            $this->reverseShareOutcome->reverse(
                ShareOutcomeBusinessKey::eventSubmission($result['submission_id']),
                'submission_rejected',
                (string) $moderator->getKey(),
            );

            return $this->rehydrateModerationModels($result);
        });

        $this->recordOccurrenceModerationSignal(
            $event,
            $submission,
            $occurrence,
            'rejected',
            $moderator,
            SubmissionPending::name(),
            SubmissionRejected::name(),
            reasonCode: $reason,
            note: $note,
        );

        $this->notifySubmitter($submission, $event, $occurrence, approved: false, reason: $reason, note: $note);
    }

    private function recordOccurrenceModerationSignal(
        Event $event,
        EventSubmission $submission,
        EventOccurrence $occurrence,
        string $action,
        User $moderator,
        string $previousStatus,
        string $currentStatus,
        ?string $reasonCode = null,
        ?string $note = null,
    ): void {
        $this->signals->record(
            eventName: 'moderation.event.occurrence_'.$action,
            eventCategory: 'moderation',
            actorId: (string) $moderator->getKey(),
            properties: [
                'event_id' => (string) $event->getKey(),
                'event_occurrence_id' => (string) $occurrence->getKey(),
                'submission_id' => (string) $submission->getKey(),
                'moderation_action' => $action,
                'previous_status' => $previousStatus,
                'current_status' => $currentStatus,
                'moderator_id' => (string) $moderator->getKey(),
                'reason_code' => $reasonCode,
                'has_note' => filled($note),
            ],
            request: request(),
        );
    }

    /**
     * @param  array{event_id: string, submission_id: string, occurrence_id: string}  $result
     * @return array{EventSubmission, Event, EventOccurrence}
     */
    private function rehydrateModerationModels(array $result): array
    {
        /** @var EventSubmission $submission */
        $submission = EventSubmission::query()->whereKey($result['submission_id'])->firstOrFail();
        /** @var Event $event */
        $event = Event::query()->whereKey($result['event_id'])->firstOrFail();
        /** @var EventOccurrence $occurrence */
        $occurrence = EventOccurrence::query()->whereKey($result['occurrence_id'])->firstOrFail();

        return [$submission, $event, $occurrence];
    }

    private function notifySubmitter(
        EventSubmission $submission,
        Event $event,
        EventOccurrence $occurrence,
        bool $approved,
        ?string $reason,
        ?string $note,
    ): void {
        $notification = new EventOccurrenceReviewedNotification($event, $occurrence, $approved, $reason, $note);

        DB::afterCommit(function () use ($submission, $notification): void {
            $submitter = $submission->submitter;

            if ($submitter instanceof User) {
                $submitter->notify($notification);

                return;
            }

            $email = $submission->contactMethods()
                ->where('type', ContactMethodType::Email->value)
                ->value('value');

            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                Notification::route('mail', $email)->notify($notification);
            }
        });
    }
}
