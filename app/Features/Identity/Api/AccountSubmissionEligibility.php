<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

/**
 * Synchronizes public-submission eligibility after account or membership changes.
 *
 * Identity and Membership call this operation instead of writing Directory flags.
 */
interface AccountSubmissionEligibility
{
    public function syncForAccount(string $accountId): void;
}
