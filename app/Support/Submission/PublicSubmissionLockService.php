<?php

namespace App\Support\Submission;

use AIArmada\Membership\Enums\MemberRole;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final readonly class PublicSubmissionLockService
{
    public function institutionEligibility(Institution $institution): SubmissionLockEligibilityResult
    {
        /** @var Collection<int, User> $members */
        $members = $institution->members()->get();

        return $this->resolveEligibility(
            $members,
            __('Tiada ahli institusi yang didaftarkan.'),
            __('Tiada ahli institusi dengan peranan owner/admin.'),
            __('Peranan owner/admin memerlukan nombor telefon yang telah disahkan.'),
        );
    }

    public function personEligibility(Person $person): SubmissionLockEligibilityResult
    {
        /** @var Collection<int, User> $members */
        $members = $person->members()->get();

        return $this->resolveEligibility(
            $members,
            __('Tiada ahli penceramah yang didaftarkan.'),
            __('Tiada ahli penceramah dengan peranan owner/admin.'),
            __('Peranan owner/admin memerlukan nombor telefon yang telah disahkan.'),
        );
    }

    public function lockInstitution(Institution $institution, User $actor): void
    {
        $this->ensureGlobalLockPermission($actor);

        $eligibility = $this->institutionEligibility($institution);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                'lock_public_submission' => $eligibility->reasons,
            ]);
        }

        $institution->forceFill([
            'allow_public_event_submission' => false,
            'public_submission_locked_at' => Carbon::now(),
            'public_submission_locked_by' => $actor->getKey(),
        ])->save();

        Cache::forget('submit_institutions');
    }

    public function lockPerson(Person $person, User $actor): void
    {
        $this->ensureGlobalLockPermission($actor);

        $eligibility = $this->personEligibility($person);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                'lock_public_submission' => $eligibility->reasons,
            ]);
        }

        $person->forceFill([
            'allow_public_event_submission' => false,
            'public_submission_locked_at' => Carbon::now(),
            'public_submission_locked_by' => $actor->getKey(),
        ])->save();

        Cache::forget('submit_persons');
    }

    public function unlockInstitution(Institution $institution, User $actor): void
    {
        $this->ensureGlobalLockPermission($actor);

        $institution->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        Cache::forget('submit_institutions');
    }

    public function unlockPerson(Person $person, User $actor): void
    {
        $this->ensureGlobalLockPermission($actor);

        $person->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        Cache::forget('submit_persons');
    }

    public function ensureInstitutionUnlockedIfIneligible(Institution $institution): bool
    {
        if ($institution->allow_public_event_submission) {
            return false;
        }

        $eligibility = $this->institutionEligibility($institution);

        if ($eligibility->eligible) {
            return false;
        }

        $institution->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        Cache::forget('submit_institutions');

        return true;
    }

    public function ensurePersonUnlockedIfIneligible(Person $person): bool
    {
        if ($person->allow_public_event_submission) {
            return false;
        }

        $eligibility = $this->personEligibility($person);

        if ($eligibility->eligible) {
            return false;
        }

        $person->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        Cache::forget('submit_persons');

        return true;
    }

    /**
     * @return array{institutions_reopened: int, persons_reopened: int}
     */
    public function sweepLockedEntities(): array
    {
        $institutionsReopened = 0;

        Institution::query()
            ->where('allow_public_event_submission', false)
            ->each(function (Institution $institution) use (&$institutionsReopened): void {
                if ($this->ensureInstitutionUnlockedIfIneligible($institution)) {
                    $institutionsReopened++;
                }
            });

        $personsReopened = 0;

        Person::query()
            ->where('allow_public_event_submission', false)
            ->each(function (Person $person) use (&$personsReopened): void {
                if ($this->ensurePersonUnlockedIfIneligible($person)) {
                    $personsReopened++;
                }
            });

        return [
            'institutions_reopened' => $institutionsReopened,
            'persons_reopened' => $personsReopened,
        ];
    }

    public function syncForUser(User $user): void
    {
        /** @var list<string> $institutionIds */
        $institutionIds = $user->institutions()
            ->where('allow_public_event_submission', false)
            ->pluck('institutions.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($institutionIds !== []) {
            Institution::query()
                ->whereIn('id', $institutionIds)
                ->each(fn (Institution $institution): bool => $this->ensureInstitutionUnlockedIfIneligible($institution));
        }

        /** @var list<string> $personIds */
        $personIds = $user->speakers()
            ->where('allow_public_event_submission', false)
            ->pluck('speakers.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($personIds !== []) {
            Person::query()
                ->whereIn('id', $personIds)
                ->each(fn (Person $person): bool => $this->ensurePersonUnlockedIfIneligible($person));
        }
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function resolveEligibility(
        Collection $members,
        string $noMembersReason,
        string $missingRoleReason,
        string $missingVerifiedPhoneReason,
    ): SubmissionLockEligibilityResult {
        if ($members->isEmpty()) {
            return SubmissionLockEligibilityResult::ineligible([$noMembersReason]);
        }

        $hasOwnerOrAdminMember = false;

        foreach ($members as $member) {
            if (! $member instanceof User) {
                continue;
            }

            $role = $member->getRelationValue('pivot')?->getAttribute('role');
            $hasRole = in_array($role, [MemberRole::Owner->value, MemberRole::Admin->value], true);

            if (! $hasRole) {
                continue;
            }

            $hasOwnerOrAdminMember = true;

            if ($member->phone_verified_at !== null) {
                return SubmissionLockEligibilityResult::eligible();
            }
        }

        if (! $hasOwnerOrAdminMember) {
            return SubmissionLockEligibilityResult::ineligible([$missingRoleReason]);
        }

        return SubmissionLockEligibilityResult::ineligible([$missingVerifiedPhoneReason]);
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureGlobalLockPermission(User $actor): void
    {
        if ($actor->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return;
        }

        throw new AuthorizationException(__('Anda tidak dibenarkan mengunci penghantaran awam.'));
    }
}
