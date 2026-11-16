<?php

declare(strict_types=1);

namespace App\Features\Identity\Services;

use App\Features\Institutions\Api\InstitutionSubmissionAccess;
use App\Features\Persons\Api\PersonSubmissionAccess;
use App\Features\Identity\Api\AccountSubmissionEligibility;

/**
 * Coordinates the the two owner submission-eligibility policies.
 */
final readonly class AccountSubmissionEligibilityService implements AccountSubmissionEligibility
{
    public function __construct(
        private InstitutionSubmissionAccess $institutions,
        private PersonSubmissionAccess $persons,
    ) {}

    public function syncForAccount(string $accountId): void
    {
        $this->institutions->syncForAccount($accountId);
        $this->persons->syncForAccount($accountId);
    }

}
