<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Read scope for guest share profiles that were actively claimed.
 *
 * Revoked claims are excluded so detached history is never readable.
 */
interface GuestClaimScope
{
    /**
     * @return \Illuminate\Database\Eloquent\Builder<\AIArmada\Affiliates\Models\AffiliateClaim>
     */
    public function guestClaims();
}
