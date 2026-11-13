<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

use App\Shared\ShareTracking\ShareGuestClaimResult;

/**
 * Explicit authenticated guest-share claim.
 *
 * Proves ownership of an anonymous share history with the private
 * single-purpose claim capability issued when guest sharing began, plus
 * the claimant's explicit confirmation. Public share tokens, email
 * addresses, IP similarity, and user-agent matching can never prove
 * ownership and are never read here.
 */
interface GuestShareClaims
{
    /**
     * Claim the guest history proven by the presented capability for one
     * account. Claim refusals are returned as decisions so callers get one
     * generic public code for invalid and already-claimed histories; only a
     * missing explicit consent throws.
     */
    public function claim(
        string $claimantId,
        string $claimantMorphType,
        string $presentedCapability,
        bool $confirmedConsent,
    ): ShareGuestClaimResult;
}
