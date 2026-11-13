<?php

namespace App\Services;

use App\Actions\ShareTracking\ReverseEventParticipationOutcomesAction;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\Draft;
use App\States\EventStatus\NeedsChanges;
use App\States\EventStatus\Pending;
use App\States\EventStatus\Rejected;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moderation Service per documentation B4 and B4a.
 */
class ModerationService
{
    public function __construct(
        private readonly ReverseShareOutcomeAction $reverseShareOutcome,
        private readonly SignalRecorder $signals,
    ) {}

    /**
     * Submit an event for moderation.
     */
    public function submitForModeration(Event $event): void
    {
        $previousStatus = (string) $event->status;
        $event->status->transitionTo(Pending::class);
        $event->refresh();

        $this->recordModerationSignal($event, 'submitted', previousStatus: $previousStatus);
    }

    /**
     * Approve an event.
     */
    public function approve(
        Event $event,
        ?User $moderator = null,
        ?string $note = null
    ): void {
        $previousStatus = (string) $event->status;
        $event->status->transitionTo(Approved::class, $moderator, $note);
        $event->refresh();

        $this->recordModerationSignal($event, 'approved', $moderator, $previousStatus, note: $note);
    }

    /**
     * Mark event as needing changes.
     */
    public function requestChanges(
        Event $event,
        User $moderator,
        string $reasonCode,
        ?string $note = null
    ): void {
        $previousStatus = (string) $event->status;
        $event->status->transitionTo(NeedsChanges::class, $moderator, $reasonCode, $note);
        $event->refresh();

        $this->recordModerationSignal($event, 'needs_changes_requested', $moderator, $previousStatus, $reasonCode, $note);
    }

    /**
     * Reject an event.
     *
     * Rejection invalidates the event-scope submission outcomes in the
     * same transaction: source qualification is the submission state,
     * and a rejected submission never qualified. Session submissions
     * keep their own scope and are never cascaded from the event.
     */
    public function reject(
        Event $event,
        User $moderator,
        string $reasonCode,
        ?string $note = null
    ): void {
        $previousStatus = (string) $event->status;

        DB::transaction(function () use ($event, $moderator, $reasonCode, $note): void {
            $event->status->transitionTo(Rejected::class, $moderator, $reasonCode, $note);
            $event->refresh();

            EventSubmission::query()
                ->where('event_id', $event->getKey())
                ->whereNull('event_session_id')
                ->lockForUpdate()
                ->pluck('id')
                ->each(function (mixed $submissionId) use ($moderator): void {
                    $this->reverseShareOutcome->handle(
                        ShareOutcomeBusinessKey::eventSubmission((string) $submissionId),
                        'submission_rejected',
                        $moderator,
                    );
                });
        });

        $this->recordModerationSignal($event, 'rejected', $moderator, $previousStatus, $reasonCode, $note);
    }

    /**
     * Cancel an event while keeping it visible to users.
     *
     * Cancellation preserves history — source rows and frozen receipts
     * stay — but the event's participation outcomes no longer qualify:
     * registrations and check-ins reverse in the same transaction as the
     * canonical status transition, cancelling pending effects. Event
     * contributions such as submissions keep their own qualification.
     */
    public function cancel(
        Event $event,
        ?User $moderator = null,
        ?string $note = null
    ): void {
        $previousStatus = (string) $event->status;

        DB::transaction(function () use ($event, $moderator, $note): void {
            $event->status->transitionTo(Cancelled::class, $moderator, $note);
            $event->refresh();

            app(ReverseEventParticipationOutcomesAction::class)->forEvent(
                $event,
                $moderator,
                'event_cancelled',
            );
        });

        $this->recordModerationSignal($event, 'cancelled', $moderator, $previousStatus, note: $note);
    }

    /**
     * Reconsider a rejected event (move back to pending).
     */
    public function reconsider(
        Event $event,
        ?User $moderator = null,
        ?string $note = null
    ): void {
        $previousStatus = (string) $event->status;
        $event->status->transitionTo(Pending::class, $moderator, $note);
        $event->refresh();

        $this->recordModerationSignal($event, 'reconsidered', $moderator, $previousStatus, note: $note);
    }

    /**
     * Revert an event to draft.
     */
    public function revertToDraft(
        Event $event,
        ?User $moderator = null,
        ?string $note = null
    ): void {
        $previousStatus = (string) $event->status;
        $event->status->transitionTo(Draft::class, $moderator, $note);
        $event->refresh();

        $this->recordModerationSignal($event, 'reverted_to_draft', $moderator, $previousStatus, note: $note);
    }

    /**
     * Send an approved event back for re-moderation.
     */
    public function remoderate(
        Event $event,
        ?User $moderator = null,
        ?string $note = null,
        ?string $reasonCode = null,
    ): void {
        $previousStatus = (string) $event->status;
        $event->status->transitionTo(Pending::class, $moderator, $note, $reasonCode);
        $event->refresh();

        $this->recordModerationSignal($event, 'remoderated', $moderator, $previousStatus, $reasonCode, $note);
    }

    private function recordModerationSignal(
        Event $event,
        string $action,
        ?User $moderator = null,
        ?string $previousStatus = null,
        ?string $reasonCode = null,
        ?string $note = null,
    ): void {
        $this->signals->record(
            eventName: 'moderation.event.'.$action,
            eventCategory: 'moderation',
            actorId: $moderator instanceof User ? (string) $moderator->getKey() : null,
            properties: [
                'event_id' => (string) $event->getKey(),
                'moderation_action' => $action,
                'previous_status' => $previousStatus,
                'current_status' => (string) $event->status,
                'moderator_id' => $moderator instanceof User ? (string) $moderator->getKey() : null,
                'reason_code' => $reasonCode,
                'has_note' => filled($note),
            ],
            request: request(),
        );
    }

    /**
     * Handle sensitive change gating.
     * Per documentation B4.
     *
     * @param  array<string, mixed>  $changes
     */
    public function handleSensitiveChange(Event $event, array $changes): void
    {
        if (! $this->isSensitiveChange($changes)) {
            return;
        }

        Log::info('Sensitive event change detected; explicit announcement required for public notification', [
            'event_id' => $event->id,
            'changed_fields' => array_keys($changes),
        ]);
    }

    /**
     * Determine whether a change is considered sensitive.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function isSensitiveChange(array $changes): bool
    {
        $sensitiveFields = [
            'institution_id',
            'venue_id',
            'starts_at',
            'ends_at',
            'timing_mode',
            'prayer_reference',
            'prayer_offset',
            'registration_required',
            'capacity',
            'area_assignments',
            'latitude',
            'longitude',
        ];

        return (bool) array_intersect($sensitiveFields, array_keys($changes));
    }
}
