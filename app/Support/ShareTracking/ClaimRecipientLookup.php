<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use App\Models\User;
use App\Services\ShareTracking\AffiliatesShareTrackingService;
use App\Shared\ShareTracking\ClaimRecipients;
use App\Shared\ShareTracking\ClaimRecipientSnapshot;
use InvalidArgumentException;

/**
 * Root adapter over the affiliate share-tracking service for guest claims.
 *
 * The feature passes a scalar claimant id, so this adapter loads the account
 * and keeps the service's owner-context, uniqueness and eligibility rules
 * authoritative.
 */
final readonly class ClaimRecipientLookup implements ClaimRecipients
{
    public function __construct(
        private AffiliatesShareTrackingService $shareTrackingService,
    ) {}

    public function ensureForClaimant(string $claimantId): ClaimRecipientSnapshot
    {
        $claimant = User::query()->whereKey($claimantId)->first();

        if (! $claimant instanceof User) {
            throw new InvalidArgumentException('Claim recipient account does not exist.');
        }

        return new ClaimRecipientSnapshot(
            affiliateId: (string) $this->shareTrackingService->ensureClaimRecipientAffiliate($claimant)->getKey(),
        );
    }
}
