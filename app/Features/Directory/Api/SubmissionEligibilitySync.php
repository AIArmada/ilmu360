<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Synchronizes public-submission eligibility after account or membership changes.
 *
 * Identity and Membership call this operation instead of writing Directory flags.
 */
interface SubmissionEligibilitySync
{
    public function syncForAccount(string $accountId): void;

    public function syncInstitution(string $institutionId): void;

    public function syncPerson(string $personId): void;

    /**
     * @return array{institutions_reopened: int, persons_reopened: int}
     */
    public function sweep(): array;
}
