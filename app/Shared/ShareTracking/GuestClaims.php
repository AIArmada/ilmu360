<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

/** Read/claim boundary over guest share profiles for Identity workflows. */
interface GuestClaims
{
    /**
     * Resolve the active claimant for a guest affiliate, optionally taking the
     * affiliate row lock first to preserve the established lock order.
     */
    public function activeClaimantId(string $affiliateId, bool $lockAffiliate = false): ?string;

    /**
     * Lock and read one guest claim by its primary key.
     */
    public function lockClaim(string $claimId): ?GuestClaimSnapshot;
}
