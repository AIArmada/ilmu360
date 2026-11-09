<?php

declare(strict_types=1);

namespace App\Support\Submission;

use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Shared\Directory\SubmissionEligibilitySync;

/**
 * Interim eligibility-sync adapter over PublicSubmissionLockService.
 *
 * Directory will own the submission flags and cache invalidation once it is
 * extracted. Until then this adapter is the shared write boundary that
 * Membership and Identity may call without touching Institution or Person.
 */
final readonly class PublicSubmissionEligibilitySync implements SubmissionEligibilitySync
{
    public function __construct(
        private PublicSubmissionLockService $locks,
    ) {}

    public function syncForAccount(string $accountId): void
    {
        $account = User::query()->find($accountId);

        if ($account instanceof User) {
            $this->locks->syncForUser($account);
        }
    }

    public function syncInstitution(string $institutionId): void
    {
        $institution = Institution::query()->find($institutionId);

        if ($institution instanceof Institution) {
            $this->locks->ensureInstitutionUnlockedIfIneligible($institution);
        }
    }

    public function syncPerson(string $personId): void
    {
        $person = Person::query()->find($personId);

        if ($person instanceof Person) {
            $this->locks->ensurePersonUnlockedIfIneligible($person);
        }
    }

    public function sweep(): array
    {
        return $this->locks->sweepLockedEntities();
    }
}
