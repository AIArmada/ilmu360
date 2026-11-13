<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

/**
 * Resolve the member affiliate that receives claimed guest share history.
 *
 * The guest source profile and the recipient are always distinct: the guest
 * row stays ownerless and the recipient is the claimant's own member profile.
 */
interface ClaimRecipients
{
    /**
     * Ensure the claimant owns a member profile eligible to receive history.
     *
     * Throws InvalidArgumentException when the claimant cannot receive it;
     * callers map that refusal to ShareGuestClaimResult::RecipientIneligible.
     */
    public function ensureForClaimant(string $claimantId): ClaimRecipientSnapshot;
}
