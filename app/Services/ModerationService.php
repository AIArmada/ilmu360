<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\Draft;
use App\States\EventStatus\NeedsChanges;
use App\States\EventStatus\Pending;
use App\States\EventStatus\Rejected;
use Illuminate\Support\Facades\Log;

/**
 * Moderation Service per documentation B4 and B4a.
 */
class ModerationService
{
    /**
     * Submit an event for moderation.
     */
    public function submitForModeration(Event $event): void
    {
        $previousStatus = (string) $event->status;
        $event->status->transitionTo(Pending::class);
        $event->refresh();

        app(ProductSignalsService::class)->recordModerationTransition($event, 'submitted', previousStatus: $previousStatus, request: request());
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

        app(ProductSignalsService::class)->recordModerationTransition($event, 'approved', $moderator, $previousStatus, note: $note, request: request());
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

        app(ProductSignalsService::class)->recordModerationTransition($event, 'needs_changes_requested', $moderator, $previousStatus, $reasonCode, $note, request());
    }

    /**
     * Reject an event.
     */
    public function reject(
        Event $event,
        User $moderator,
        string $reasonCode,
        ?string $note = null
    ): void {
        $previousStatus = (string) $event->status;
        $event->status->transitionTo(Rejected::class, $moderator, $reasonCode, $note);
        $event->refresh();

        app(ProductSignalsService::class)->recordModerationTransition($event, 'rejected', $moderator, $previousStatus, $reasonCode, $note, request());
    }

    /**
     * Cancel an event while keeping it visible to users.
     */
    public function cancel(
        Event $event,
        ?User $moderator = null,
        ?string $note = null
    ): void {
        $previousStatus = (string) $event->status;
        $event->status->transitionTo(Cancelled::class, $moderator, $note);
        $event->refresh();

        app(ProductSignalsService::class)->recordModerationTransition($event, 'cancelled', $moderator, $previousStatus, note: $note, request: request());
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

        app(ProductSignalsService::class)->recordModerationTransition($event, 'reconsidered', $moderator, $previousStatus, note: $note, request: request());
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

        app(ProductSignalsService::class)->recordModerationTransition($event, 'reverted_to_draft', $moderator, $previousStatus, note: $note, request: request());
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

        app(ProductSignalsService::class)->recordModerationTransition($event, 'remoderated', $moderator, $previousStatus, $reasonCode, $note, request());
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
            'state_id',
            'district_id',
            'subdistrict_id',
            'lat',
            'lng',
        ];

        return (bool) array_intersect($sensitiveFields, array_keys($changes));
    }
}
