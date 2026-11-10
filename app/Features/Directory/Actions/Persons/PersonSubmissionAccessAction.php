<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use App\Features\Directory\Api\PersonSubmissionAccess;
use App\Features\Directory\Api\PersonSubmissionEligibility;
use App\Features\Directory\Persistence\PersonSubmissionCache;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Owns the person public-submission flags and their eligibility rules.
 */
final readonly class PersonSubmissionAccessAction implements PersonSubmissionAccess
{
    private const array LOCK_ROLES = ['super_admin', 'admin', 'moderator'];

    public function __construct(
        private MembershipDirectory $memberships,
        private AccountDirectory $accounts,
        private PersonSubmissionCache $cache,
    ) {}

    public function eligibility(string $personId): PersonSubmissionEligibility
    {
        return $this->eligibilityFor($this->person($personId));
    }

    public function lock(string $personId, string $actorId): void
    {
        $this->ensureGlobalLockPermission($actorId);

        $person = $this->person($personId);
        $eligibility = $this->eligibilityFor($person);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                'lock_public_submission' => $eligibility->reasons,
            ]);
        }

        $person->forceFill([
            'allow_public_event_submission' => false,
            'public_submission_locked_at' => Carbon::now(),
            'public_submission_locked_by' => $actorId,
        ])->save();

        $this->cache->forget();
    }

    public function unlock(string $personId, string $actorId): void
    {
        $this->ensureGlobalLockPermission($actorId);

        $person = $this->person($personId);

        $person->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        $this->cache->forget();
    }

    public function reopenIfIneligible(string $personId): bool
    {
        $person = $this->person($personId);

        if ($person->getAttribute('allow_public_event_submission')) {
            return false;
        }

        if ($this->eligibilityFor($person)->eligible) {
            return false;
        }

        $person->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        $this->cache->forget();

        return true;
    }

    public function syncForAccount(string $accountId): int
    {
        $personIds = DirectoryModel::query('person')
            ->whereKey($this->memberships->subjectIdsFor($accountId, MembershipSubjectType::Person))
            ->where('allow_public_event_submission', false)
            ->pluck('persons.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $reopened = 0;

        foreach ($personIds as $personId) {
            if ($this->reopenIfIneligible($personId)) {
                $reopened++;
            }
        }

        return $reopened;
    }

    public function sweep(): int
    {
        $reopened = 0;

        DirectoryModel::query('person')
            ->where('allow_public_event_submission', false)
            ->each(function (Model $person) use (&$reopened): void {
                if ($this->reopenIfIneligible((string) $person->getKey())) {
                    $reopened++;
                }
            });

        return $reopened;
    }

    private function eligibilityFor(Model $person): PersonSubmissionEligibility
    {
        $person->loadMissing('members');

        $members = $person->getRelation('members');

        if (! $members instanceof Collection || $members->isEmpty()) {
            return PersonSubmissionEligibility::ineligible([
                __('Tiada ahli penceramah yang didaftarkan.'),
            ]);
        }

        $hasOwnerOrAdminMember = false;

        foreach ($members as $member) {
            $role = $member instanceof Model ? $member->getRelationValue('pivot')?->getAttribute('role') : null;
            $hasRole = in_array($role, ['owner', 'admin'], true);

            if (! $hasRole) {
                continue;
            }

            $hasOwnerOrAdminMember = true;

            if ($member instanceof Model && $member->getAttribute('phone_verified_at') !== null) {
                return PersonSubmissionEligibility::eligible();
            }
        }

        if (! $hasOwnerOrAdminMember) {
            return PersonSubmissionEligibility::ineligible([
                __('Tiada ahli penceramah dengan peranan owner/admin.'),
            ]);
        }

        return PersonSubmissionEligibility::ineligible([
            __('Peranan owner/admin memerlukan nombor telefon yang telah disahkan.'),
        ]);
    }

    private function person(string $personId): Model
    {
        $person = DirectoryModel::query('person')->find($personId);

        if (! $person instanceof Model) {
            throw new RuntimeException("Person [{$personId}] was not found.");
        }

        return $person;
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureGlobalLockPermission(string $actorId): void
    {
        if (array_intersect($this->accounts->roles($actorId), self::LOCK_ROLES) !== []) {
            return;
        }

        throw new AuthorizationException(__('Anda tidak dibenarkan mengunci penghantaran awam.'));
    }
}
