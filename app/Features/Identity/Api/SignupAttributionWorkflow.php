<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

use App\Shared\Auth\SignupAttributionContext;

/**
 * Identity-owned signup attribution workflow for conventional auth entry
 * points (Fortify registration, model verification hooks, share claims).
 */
interface SignupAttributionWorkflow
{
    public function recordForNewAccount(
        string $accountId,
        SignupAttributionContext $context,
        bool $wasRecentlyCreated,
    ): ?SignupAttributionSnapshot;

    public function confirmForAccount(string $accountId): ?SignupAttributionSnapshot;

    public function linkGuestReferralsForClaim(
        string $claimId,
        string $affiliateId,
        string $claimantType,
        string $claimantId,
    ): int;
}
