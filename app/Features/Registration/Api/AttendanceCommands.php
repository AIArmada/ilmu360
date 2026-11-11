<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Public self-check-in boundary.
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
}
