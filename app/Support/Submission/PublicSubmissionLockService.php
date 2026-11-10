<?php

namespace App\Support\Submission;

use App\Features\Directory\Api\InstitutionSubmissionAccess;
use App\Features\Directory\Api\PersonSubmissionAccess;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;

final readonly class PublicSubmissionLockService
{
    public function __construct(
        private InstitutionSubmissionAccess $institutions,
        private PersonSubmissionAccess $persons,
    ) {}

    public function institutionEligibility(Institution $institution): SubmissionLockEligibilityResult
    {
        $eligibility = $this->institutions->eligibility((string) $institution->getKey());

        return $eligibility->eligible
            ? SubmissionLockEligibilityResult::eligible()
            : SubmissionLockEligibilityResult::ineligible($eligibility->reasons);
    }

    public function personEligibility(Person $person): SubmissionLockEligibilityResult
    {
        $eligibility = $this->persons->eligibility((string) $person->getKey());

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

    public function ensureInstitutionUnlockedIfIneligible(Institution $institution): bool
    {
        return $this->institutions->reopenIfIneligible((string) $institution->getKey());
    }

    public function ensurePersonUnlockedIfIneligible(Person $person): bool
    {
        return $this->persons->reopenIfIneligible((string) $person->getKey());
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
