<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSubmissionLog;
use AIArmada\Events\States\EventModerationStatus\Approved as SubmissionApproved;
use AIArmada\Events\States\EventModerationStatus\Pending as SubmissionPending;
use AIArmada\Events\States\EventModerationStatus\Rejected as SubmissionRejected;
use AIArmada\Events\States\OccurrenceStatus\Archived;
use AIArmada\Events\States\OccurrenceStatus\Scheduled;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Notifications\EventOccurrenceReviewedNotification;
use App\Services\Signals\ProductSignalsService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

final readonly class ModerateEventOccurrenceSubmissionAction
{
    public function __construct(
        private ReverseShareOutcomeAction $reverseShareOutcome,
        private ProductSignalsService $productSignals,
    ) {}

    public function approve(EventSubmission $submission, User $moderator, ?string $note = null): void
    {
        [$submission, $event, $occurrence] = DB::transaction(function () use ($submission, $moderator, $note): array {
            [$submission, $event, $occurrence] = $this->lockedSubmission($submission);
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

            $this->recordReview($submission, $event, $occurrence, $moderator, 'occurrence_approved', $note);

            return [$submission, $event, $occurrence];
        });

        $this->productSignals->recordOccurrenceModerationTransition(
            event: $event,
            submission: $submission,
            occurrence: $occurrence,
            action: 'approved',
            moderator: $moderator,
            previousStatus: SubmissionPending::name(),
            currentStatus: SubmissionApproved::name(),
            request: request(),
            note: $note,
        );

        $this->notifySubmitter($submission, $event, $occurrence, approved: true, reason: null, note: $note);
    }

    public function reject(EventSubmission $submission, User $moderator, string $reason, string $note): void
    {
        [$submission, $event, $occurrence] = DB::transaction(function () use ($submission, $moderator, $reason, $note): array {
            [$submission, $event, $occurrence] = $this->lockedSubmission($submission);
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

            $this->recordReview($submission, $event, $occurrence, $moderator, 'occurrence_rejected', $note, $reason);
            $this->reverseShareOutcome->handle(
                ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
                'submission_rejected',
                $moderator,
                request(),
            );

            return [$submission, $event, $occurrence];
        });

        $this->productSignals->recordOccurrenceModerationTransition(
            event: $event,
            submission: $submission,
            occurrence: $occurrence,
            action: 'rejected',
            moderator: $moderator,
            previousStatus: SubmissionPending::name(),
            currentStatus: SubmissionRejected::name(),
            request: request(),
            reasonCode: $reason,
            note: $note,
        );

        $this->notifySubmitter($submission, $event, $occurrence, approved: false, reason: $reason, note: $note);
    }

    /**
     * @return array{EventSubmission, Event, EventOccurrence}
     */
    private function lockedSubmission(EventSubmission $submission): array
    {
        $lockedSubmission = EventSubmission::query()
            ->whereKey($submission->getKey())
            ->lockForUpdate()
            ->first();

        if (! $lockedSubmission instanceof EventSubmission) {
            throw ValidationException::withMessages([
                'submission' => __('This submission is no longer available.'),
            ]);
        }

        $event = Event::query()
            ->whereKey($lockedSubmission->event_id)
            ->lockForUpdate()
            ->first();
        $occurrence = EventOccurrence::query()
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
        EventSubmission $submission,
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
        EventSubmission $submission,
        Event $event,
        EventOccurrence $occurrence,
        User $moderator,
        string $action,
        ?string $note,
        ?string $reason = null,
    ): void {
        OwnerContext::withOwner(null, fn (): EventSubmissionLog => EventSubmissionLog::query()->create([
            'event_submission_id' => $submission->getKey(),
            'action' => $action,
            'performed_by_type' => $moderator->getMorphClass(),
            'performed_by_id' => $moderator->getKey(),
            'notes' => $note,
            'metadata' => array_filter([
                'event_id' => (string) $event->getKey(),
                'event_occurrence_id' => (string) $occurrence->getKey(),
                'reason_code' => $reason,
            ], static fn (mixed $value): bool => $value !== null),
        ]));
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
