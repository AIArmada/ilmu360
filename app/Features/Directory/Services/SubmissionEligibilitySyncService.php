<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Api\DirectoryReader;
use App\Features\Directory\Api\DirectoryRecordType;
use App\Features\Directory\Api\InstitutionSubmissionAccess;
use App\Features\Directory\Api\PersonSubmissionAccess;
use App\Features\Directory\Api\SubmissionEligibilitySync;

/**
 * Coordinates the two Directory submission-eligibility policies.
 */
final readonly class SubmissionEligibilitySyncService implements SubmissionEligibilitySync
{
    public function __construct(
        private InstitutionSubmissionAccess $institutions,
        private PersonSubmissionAccess $persons,
        private DirectoryReader $reader,
    ) {}

    public function syncForAccount(string $accountId): void
    {
        $this->institutions->syncForAccount($accountId);
        $this->persons->syncForAccount($accountId);
    }

    public function syncInstitution(string $institutionId): void
    {
        $resolvedId = $this->reader->resolveIdentifier(DirectoryRecordType::Institution, $institutionId);

        if ($resolvedId === null) {
            return;
        }

        $this->institutions->reopenIfIneligible($resolvedId);
    }

    public function syncPerson(string $personId): void
    {
        $resolvedId = $this->reader->resolveIdentifier(DirectoryRecordType::Person, $personId);

        if ($resolvedId === null) {
            return;
        }

        $this->persons->reopenIfIneligible($resolvedId);
    }

    public function sweep(): array
    {
        return [
            'institutions_reopened' => $this->institutions->sweep(),
            'persons_reopened' => $this->persons->sweep(),
        ];
    }
}
