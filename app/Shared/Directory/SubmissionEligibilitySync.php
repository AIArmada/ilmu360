<?php

declare(strict_types=1);

namespace App\Shared\Directory;

/**
 * Public Directory eligibility-sync operation.
 *
 * Membership and Identity call this after member or verification changes
 * instead of writing Directory submission flags directly.
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
