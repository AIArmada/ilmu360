<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Event moderation boundary.
 *
 * Root admin/MCP/moderation-queue callers use this contract while the event
 * state machine, notifications, share reversals and the conventional
 * ModerationService stay in place. Methods take scalar identifiers and plain
 * arrays only; no Eloquent models cross this boundary.
 */
interface EventModeration
{
    /**
     * Submit a draft event for moderation.
     */
    public function submitForModeration(string $eventId): void;

    /**
     * Approve a pending event.
     */
    public function approve(string $eventId, ?string $actorId, ?string $note = null): void;

    /**
     * Move a pending event to needs-changes.
     */
    public function requestChanges(string $eventId, string $actorId, string $reasonCode, ?string $note = null): void;

    /**
     * Reject a pending event and reverse its event-scope submission outcomes.
     */
    public function reject(string $eventId, string $actorId, string $reasonCode, ?string $note = null): void;

    /**
     * Cancel an event while keeping its public record visible.
     */
    public function cancel(string $eventId, ?string $actorId, ?string $note = null): void;

    /**
     * Reconsider a rejected event by moving it back to pending.
     */
    public function reconsider(string $eventId, ?string $actorId, ?string $note = null): void;

    /**
     * Revert an event to draft.
     */
    public function revertToDraft(string $eventId, ?string $actorId, ?string $note = null): void;

    /**
     * Send an approved event back for re-moderation.
     */
    public function remoderate(string $eventId, ?string $actorId, ?string $note = null, ?string $reasonCode = null): void;

    /**
     * Log sensitive changes that require an explicit public announcement.
     *
     * @param  array<string, mixed>  $changes
     */
    public function handleSensitiveChange(string $eventId, array $changes): void;

    /**
     * Moderation actions available for the event's current state.
     *
     * @return array<string, array{label: string, description: string, requires_reason_code: bool, requires_note: bool}>
     */
    public function availableActions(string $eventId): array;

    /**
     * @return array<string, string>
     */
    public function reasonOptions(): array;

    /**
     * Action keys accepted by the moderation contract.
     *
     * @return list<string>
     */
    public function actionKeys(): array;
}
