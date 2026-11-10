<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Public operation for institution public-submission flags.
 *
 * Membership, Identity and the legacy submission-lock service call this
 * instead of writing Directory columns directly.
 */
interface InstitutionSubmissionAccess
{
    public function eligibility(string $institutionId): InstitutionSubmissionEligibility;

    public function lock(string $institutionId, string $actorId): void;

    public function unlock(string $institutionId, string $actorId): void;

    public function reopenIfIneligible(string $institutionId): bool;

    /**
     * Reopen every ineligible locked institution owned by the account.
     *
     * @return int Number of institutions reopened.
     */
    public function syncForAccount(string $accountId): int;

    /**
     * Reopen every locked institution that is no longer eligible.
     *
     * @return int Number of institutions reopened.
     */
    public function sweep(): int;
}
