<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Contracts\Rewards\RewardReservationResolver;

/**
 * Default reservation resolver: no host attestation exists.
 *
 * Refusing to void unknown reservations is the only truthful behavior before
 * an accepted host adapter can confirm non-submission. Reversals with active
 * reservations therefore block until the reservations resolve through the
 * ordinary release/capture actions or an explicit resolver is supplied. Never
 * invent external cancellation or success here.
 */
final class NullRewardReservationResolver implements RewardReservationResolver
{
    public function mayVoidReservation(string $reservationKey): bool
    {
        return false;
    }
}
