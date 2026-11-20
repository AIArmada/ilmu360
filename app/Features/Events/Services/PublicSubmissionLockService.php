<?php

namespace App\Features\Events\Services;

use App\Features\Events\Api\PublicSubmissionLockSweeper;
use App\Features\Events\Api\SubmissionLockEligibilityResult;
use App\Features\Institutions\Api\InstitutionSubmissionAccess;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Api\PersonSubmissionAccess;
use App\Features\Persons\Models\Person;
use App\Models\User;

final readonly class PublicSubmissionLockService implements PublicSubmissionLockSweeper
{
    public function __construct(
        private InstitutionSubmissionAccess $institutions,
        private PersonSubmissionAccess $persons,
    ) {}

    public function institutionEligibility(string $institutionId): SubmissionLockEligibilityResult
    {
        $eligibility = $this->institutions->eligibility($institutionId);

        return $eligibility->eligible
            ? SubmissionLockEligibilityResult::eligible()
            : SubmissionLockEligibilityResult::ineligible($eligibility->reasons);
    }

    public function personEligibility(string $personId): SubmissionLockEligibilityResult
    {
        $eligibility = $this->persons->eligibility($personId);

        return $eligibility->eligible
            ? SubmissionLockEligibilityResult::eligible()
            : SubmissionLockEligibilityResult::ineligible($eligibility->reasons);
    }

    public function lockInstitution(Institution $institution, User $actor): void
    {
        $this->institutions->lock((string) $institution->getKey(), (string) $actor->getKey());
    }

    public function lockPerson(Person $person, User $actor): void
    {
        $this->persons->lock((string) $person->getKey(), (string) $actor->getKey());
    }

    public function unlockInstitution(Institution $institution, User $actor): void
    {
        $this->institutions->unlock((string) $institution->getKey(), (string) $actor->getKey());
    }

    public function unlockPerson(Person $person, User $actor): void
    {
        $this->persons->unlock((string) $person->getKey(), (string) $actor->getKey());
    }

    public function ensureInstitutionUnlockedIfIneligible(string $institutionId): void
    {
        $this->institutions->reopenIfIneligible($institutionId);
    }

    public function ensurePersonUnlockedIfIneligible(string $personId): void
    {
        $this->persons->reopenIfIneligible($personId);
    }

    /**
     * @return array{institutions_reopened: int, persons_reopened: int}
     */
    public function sweepLockedEntities(): array
    {
        return [
            'institutions_reopened' => $this->institutions->sweep(),
            'persons_reopened' => $this->persons->sweep(),
        ];
    }

    public function syncForUser(User $user): void
    {
        $accountId = (string) $user->getKey();

        $this->institutions->syncForAccount($accountId);
        $this->persons->syncForAccount($accountId);
    }
}
