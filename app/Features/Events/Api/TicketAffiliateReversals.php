<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Reverses one frozen ticket commission, retaining payout-reservation deferrals. */
interface TicketAffiliateReversals
{
    /** Return the reversed conversion ID; null for missing, guarded, or deferred. */
    public function reverse(string $registrationId, string $reason): ?string;

    public function hasOpenPayoutReservation(string $conversionId): bool;
}
