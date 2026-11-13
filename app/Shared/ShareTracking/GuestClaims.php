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

    /**
     * Lock the claimant's users row, reporting whether the account exists.
     *
     * Guest claims take this lock first so a deleted account refuses before
     * any package capability or affiliate row is touched.
     */
    public function lockClaimant(string $claimantId): bool;

    /**
     * Lock the ownerless affiliate row and report whether it is a marked guest
     * profile. The affiliate row is never adopted or rewritten.
     */
    public function lockMarkedGuestAffiliate(string $affiliateId): bool;

    /**
     * Read the guest claim for one affiliate regardless of status, optionally
     * taking the claim row lock first.
     */
    public function claimForAffiliate(string $affiliateId, bool $lock = false): ?GuestClaimSnapshot;

    /**
     * Read the guest claim carrying the presented capability's verifier hash.
     */
    public function findClaimByVerifier(string $verifier): ?GuestClaimSnapshot;
}
