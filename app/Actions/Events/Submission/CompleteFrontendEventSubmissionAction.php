<?php

declare(strict_types=1);

namespace App\Actions\Events\Submission;

use AIArmada\Events\Models\EventOccurrence;
use App\Contracts\ShareTrackingContract;
use App\Enums\ShareOutcomeType;
use App\Features\Events\Api\EventSubmissions;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Notifications\EventOccurrenceSubmittedNotification;
use App\Services\ModerationService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Throwable;

/**
 * Interim submission-completion adapter.
 *
 * Submission-owned writes (guest contacts, open-door admission and access
 * policy) go through Events\Api\EventSubmissions. Moderation still calls the
 * root ModerationService until E3b, and share outcomes stay with this root
 * orchestrator.
 */
final readonly class CompleteFrontendEventSubmissionAction
{
    public function __construct(
        private ShareTrackingContract $shareTracking,
        private ModerationService $moderation,
        private EventSubmissions $submissions,
    ) {}

    /**
     * Persist the database completion writes. Must run inside the submission
     * transaction so a failure rolls the whole submission back.
     *
     * @param  array<string, mixed>  $state
     */
    public function handle(
        Event $event,
        EventSubmission $submission,
        array $state,
        ?User $submitter,
        bool $sessionSubmission,
        bool $autoApproved,
        ?Request $request = null,
        ?EventOccurrence $occurrence = null,
        bool $parentCreated = true,
        bool $groupedSubmission = false,
        bool $occurrencePendingReview = false,
    ): void {
        $moderationAction = $this->submissions->completeWrites(
            eventId: (string) $event->getKey(),
            submissionId: (string) $submission->getKey(),
            state: $state,
            storeSubmitterContacts: ! $submitter instanceof User,
            sessionSubmission: $sessionSubmission,
            parentCreated: $parentCreated,
            autoApproved: $autoApproved,
        );

        // Session submissions attach to an existing container; the parent
        // keeps its admission defaults and lifecycle state untouched.
        if ($sessionSubmission) {
            $this->shareTracking->recordOutcome(
                type: ShareOutcomeType::EventSubmission,
                businessKey: ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
                subject: $event,
                actor: $submitter,
                request: $request ?? request(),
                metadata: [
                    'submission_id' => $submission->getKey(),
                    'session_submission' => true,
                    'event_occurrence_id' => $occurrence?->getKey(),
                ],
                occurredAt: Carbon::instance($submission->created_at ?? now()),
            );

            return;
        }

        if (! $parentCreated) {
            if ($occurrencePendingReview && $occurrence instanceof EventOccurrence) {
                $this->notifyOccurrenceNeedsReview($event, $occurrence);
            }

            $this->shareTracking->recordOutcome(
                type: ShareOutcomeType::EventSubmission,
                businessKey: ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
                subject: $event,
                actor: $submitter,
                request: $request ?? request(),
                metadata: [
                    'submission_id' => $submission->getKey(),
                    'event_occurrence_id' => $occurrence?->getKey(),
                    'grouped_submission' => $groupedSubmission,
                    'occurrence_pending_review' => $occurrencePendingReview,
                ],
                occurredAt: Carbon::instance($submission->created_at ?? now()),
            );

            return;
        }

        $event->refresh();

        if ($moderationAction === 'approve') {
            $this->moderation->approve($event, $submitter, 'Auto-approved from institution dashboard submission.');
        } elseif ($moderationAction === 'submit') {
            $this->moderation->submitForModeration($event);
        }

        $this->shareTracking->recordOutcome(
            type: ShareOutcomeType::EventSubmission,
            businessKey: ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
            subject: $event,
            actor: $submitter,
            request: $request ?? request(),
            metadata: [
                'submission_id' => $submission->getKey(),
                'event_occurrence_id' => $occurrence?->getKey(),
                'grouped_submission' => $groupedSubmission,
            ],
            occurredAt: Carbon::instance($submission->created_at ?? now()),
        );
    }

    private function notifyOccurrenceNeedsReview(Event $event, EventOccurrence $occurrence): void
    {
        DB::afterCommit(function () use ($event, $occurrence): void {
            try {
                $moderators = User::role(['moderator', 'super_admin'])->get();

                if ($moderators->isNotEmpty()) {
                    Notification::send($moderators, new EventOccurrenceSubmittedNotification($event, $occurrence));
                }
            } catch (RoleDoesNotExist $exception) {
                Log::warning('Could not notify moderators about a submitted event occurrence.', [
                    'event_id' => (string) $event->getKey(),
                    'event_occurrence_id' => (string) $occurrence->getKey(),
                    'exception' => $exception,
                ]);
            } catch (Throwable $exception) {
                Log::warning('Occurrence submission notification failed after submit.', [
                    'event_id' => (string) $event->getKey(),
                    'event_occurrence_id' => (string) $occurrence->getKey(),
                    'exception' => $exception,
                ]);
            }
        });
    }
}
