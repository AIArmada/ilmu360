<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Public attendance-state read boundary.
 *
 * Returns the same availability decision the self-check-in command consumes so
 * HTTP and Livewire entry points share one eligibility rule.
 */
interface AttendanceState
{
    /**
     * @return array{
     *   available: bool,
     *   reason: string|null,
     *   method: 'self_reported'|'registered_self_checkin',
     *   registration_id: string|null
     * }
     */
    public function forUser(string $eventId, string $userId): array;
}
