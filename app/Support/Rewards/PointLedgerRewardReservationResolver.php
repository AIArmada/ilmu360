<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Contracts\Rewards\RewardReservationResolver;
use App\Models\RewardPointReservationSubmission;

/**
 * Production reservation attestation over the submission registry.
 *
 * Answers true ONLY when the reservation was provably never submitted
 * externally: no submission row exists, or the single handoff is confirmed
 * failed with a resolution stamp. Sending, submitted, unknown, and delivered
 * handoffs all refuse.
 *
 * Locking contract: the caller (award reversal) must already hold the
 * reservation's lot locks on this connection, and every mark-submit flow
 * must lock the same lots before writing (see
 * MarkRewardReservationSubmittedAction). The lot locks — not this lookup —
 * serialize mark-submit against reversal; a missing registry row locks
 * nothing by itself. This lookup additionally locks a present registry row
 * so concurrent mark transitions serialize with the read.
 */
final class PointLedgerRewardReservationResolver implements RewardReservationResolver
{
    public function mayVoidReservation(string $reservationKey): bool
    {
        if ($reservationKey === '' || mb_strlen($reservationKey) > 191) {
            return false;
        }

        $submission = RewardPointReservationSubmission::query()
            ->where('reservation_key', $reservationKey)
            ->lockForUpdate()
            ->first();

        if (! $submission instanceof RewardPointReservationSubmission) {
            return true;
        }

        return (string) $submission->getAttribute('status') === RewardPointReservationSubmission::STATUS_FAILED
            && $submission->getAttribute('resolved_at') !== null;
    }
}
