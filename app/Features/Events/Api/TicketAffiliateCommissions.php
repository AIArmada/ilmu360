<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Commercial ticket commission recording from a paid checkout's frozen
 * attribution. Payout and reversal remain independent guarded operations.
 */
interface TicketAffiliateCommissions
{
    /**
     * @param  list<string>  $registrationIds
     * @return list<string> IDs recorded during the attempt.
     */
    public function recordForCheckout(string $checkoutSessionId, array $registrationIds): array;
}
