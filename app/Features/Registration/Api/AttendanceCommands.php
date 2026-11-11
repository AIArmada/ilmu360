<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Public attendance command boundary.
 *
 * Callers outside Registration pass stable scalar identities and receive only
 * immutable scalar results. The feature owns the attendance write path while
 * the events package remains the canonical external attendance writer.
 *
 * `status` reports whether this call created the attendance row or replayed an
 * existing one. `checkin_id` always identifies the attendance row so callers
 * can render their response from the authoritative record.
 */
interface AttendanceCommands
{
    /**
     * @return array{status: 'created'|'duplicate', checkin_id: string}
     */
    public function recordSelfCheckIn(
        string $eventId,
        string $userId,
        ?string $registrationId,
        string $method,
        ?object $request = null,
    ): array;

    /**
     * Record a staff-assisted check-in for one participant.
     *
     * Event scope writes the overall attendance record; occurrence and session
     * scope use the package check-in service, which rejects wrong-scope
     * presentations without writing attendance data.
     *
     * @return array{status: 'created'|'duplicate', checkin_id: string}
     */
    public function recordParticipantCheckIn(
        string $eventId,
        string $participantId,
        string $staffId,
        ?string $occurrenceId = null,
        ?string $sessionId = null,
    ): array;

    /**
     * Record an explicit attended or did-not-attend decision.
     *
     * Returns the attendance identifier. `not_recorded` is a clear operation,
     * not a persisted state.
     */
    public function setParticipantAttendance(
        string $eventId,
        string $participantId,
        string $status,
        string $actorId,
        ?string $occurrenceId = null,
        ?string $sessionId = null,
        ?string $note = null,
        string $source = 'manual_attendance',
    ): string;

    /**
     * Remove the active attendance decision while preserving its audit trail.
     */
    public function clearAttendance(
        string $eventId,
        string $participantId,
        string $actorId,
        ?string $occurrenceId = null,
        ?string $sessionId = null,
        ?string $note = null,
        string $source = 'manual_attendance',
    ): void;
}
