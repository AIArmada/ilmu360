<?php

declare(strict_types=1);

namespace App\Contracts\Rewards;

/**
 * Truthful reservation-resolution boundary for award reversal.
 *
 * A point reservation is an internal hold that a host flow may already have
 * submitted to external fulfillment (redemption, voucher issue, provider
 * call). The ledger cannot observe that submission, so reversal must ask the
 * host before voiding an active reservation: true ONLY when the host confirms
 * the reservation was never submitted externally and its held value may be
 * voided atomically during reversal. Any other answer (false/unknown) blocks
 * the reversal — the award keeps its status, the reservations stay pending,
 * and the caller retries after ordinary release/capture or explicit host
 * attestation. The default null implementation always answers false; fixture
 * attestations in tests never certify host provider integration.
 */
interface RewardReservationResolver
{
    public function mayVoidReservation(string $reservationKey): bool;
}
