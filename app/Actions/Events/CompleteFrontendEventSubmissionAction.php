<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Models\EventOccurrence;
use App\Contracts\ShareTrackingContract;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Notifications\EventOccurrenceSubmittedNotification;
use App\Services\ModerationService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Throwable;

final readonly class CompleteFrontendEventSubmissionAction
{
    public function __construct(
        private ShareTrackingContract $shareTracking,
        private ModerationService $moderation,
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
        if (! $submitter instanceof User) {
            $this->storeSubmitterContacts($submission, $state);
        }

        // Session submissions attach to an existing container; the parent
        // keeps its admission defaults and lifecycle state untouched.
        if ($sessionSubmission) {
            $this->shareTracking->recordOutcome(
                type: DawahShareOutcomeType::EventSubmission,
                businessKey: ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
                subject: $event,
                actor: $submitter,
                request: $request ?? request(),
                metadata: [
                    'submission_id' => $submission->getKey(),
                    'session_submission' => true,
                    'event_occurrence_id' => $occurrence?->getKey(),
                ],
                occurredAt: $submission->created_at ?? now(),
            );

            return;
        }

        if (! $parentCreated) {
            if ($occurrencePendingReview && $occurrence instanceof EventOccurrence) {
                $this->notifyOccurrenceNeedsReview($event, $occurrence);
            }

            $this->shareTracking->recordOutcome(
                type: DawahShareOutcomeType::EventSubmission,
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
                occurredAt: $submission->created_at ?? now(),
            );

            return;
        }

        $event->forceFill(['registration_mode' => RegistrationMode::None->value])->save();
        $event->accessPolicy()->updateOrCreate(
            ['event_id' => $event->getKey()],
            ['registration_required' => false, 'walk_in_allowed' => true],
        );

        if ($autoApproved) {
            $this->moderation->approve($event, $submitter, 'Auto-approved from institution dashboard submission.');
        } elseif ((string) $event->status === 'draft') {
            $this->moderation->submitForModeration($event);
        }

        $this->shareTracking->recordOutcome(
            type: DawahShareOutcomeType::EventSubmission,
            businessKey: ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
            subject: $event,
            actor: $submitter,
            request: $request ?? request(),
            metadata: [
                'submission_id' => $submission->getKey(),
                'event_occurrence_id' => $occurrence?->getKey(),
                'grouped_submission' => $groupedSubmission,
            ],
            occurredAt: $submission->created_at ?? now(),
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function storeSubmitterContacts(EventSubmission $submission, array $state): void
    {
        $order = 1;

        foreach (['submitter_email' => ContactMethodType::Email, 'submitter_phone' => ContactMethodType::Phone] as $key => $type) {
            if (! filled($state[$key] ?? null)) {
                continue;
            }

            $submission->contactMethods()->create([
                'type' => $type->value,
                'purpose' => ContactPurpose::General->value,
                'value' => $state[$key],
                'is_public' => false,
                'sort_order' => $order++,
            ]);
        }
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
