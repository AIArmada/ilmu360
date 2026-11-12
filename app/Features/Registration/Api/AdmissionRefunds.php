<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Public admission refund boundary.
 *
 * Callers outside Registration pass stable scalar identities and receive only
 * immutable scalar facts about the refund record. Registration owns the
 * application-side refund orchestration: purchaser/organizer authorization,
 * refund eligibility, order resolution, pending-refund creation and claiming,
 * provider submission, and the pending/completed/failed transitions. The
 * orders and checkout packages remain the canonical writers for refund rows
 * and money movement; this boundary never returns mutable Eloquent models.
 *
 * `status` is the current refund-record state name and `is_pending` reports
 * whether the provider confirmation is still outstanding. `refund_id`
 * identifies the authoritative order refund so callers can re-fetch it when
 * their display needs more than these scalar facts.
 */
interface AdmissionRefunds
{
    /**
     * @return array{refund_id: string, status: string, is_pending: bool}
     */
    public function process(
        string $eventId,
        string $registrationId,
        string $actorId,
        bool $organizerOverride = false,
        ?string $reason = null,
    ): array;
}
