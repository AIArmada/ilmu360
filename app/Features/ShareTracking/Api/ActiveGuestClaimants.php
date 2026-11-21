<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Resolves the active claimant of a marked guest affiliate without exposing
 * affiliate claims or a mutable database query builder.
 */
interface ActiveGuestClaimants
{
    public function activeClaimantIdForAffiliate(string $affiliateId): ?string;
}
