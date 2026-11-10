<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Api\InstitutionSubmissionAccess;
use App\Features\Directory\Api\InstitutionSubmissionEligibility;
use App\Features\Directory\Persistence\InstitutionSubmissionCache;
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
 * Owns the institution public-submission flags and their eligibility rules.
 */
final readonly class InstitutionSubmissionAccessAction implements InstitutionSubmissionAccess
{
    private const array LOCK_ROLES = ['super_admin', 'admin', 'moderator'];

    public function __construct(
        private MembershipDirectory $memberships,
        private AccountDirectory $accounts,
        private InstitutionSubmissionCache $cache,
    ) {}

    public function eligibility(string $institutionId): InstitutionSubmissionEligibility
    {
        return $this->eligibilityFor($this->institution($institutionId));
    }

    public function lock(string $institutionId, string $actorId): void
    {
        $this->ensureGlobalLockPermission($actorId);

        $institution = $this->institution($institutionId);
        $eligibility = $this->eligibilityFor($institution);

        if (! $eligibility->eligible) {
            throw ValidationException::withMessages([
                'lock_public_submission' => $eligibility->reasons,
            ]);
        }

        $institution->forceFill([
            'allow_public_event_submission' => false,
            'public_submission_locked_at' => Carbon::now(),
            'public_submission_locked_by' => $actorId,
        ])->save();

        $this->cache->forget();
    }

    public function unlock(string $institutionId, string $actorId): void
    {
        $this->ensureGlobalLockPermission($actorId);

        $institution = $this->institution($institutionId);

        $institution->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        $this->cache->forget();
    }

    public function reopenIfIneligible(string $institutionId): bool
    {
        $institution = $this->institution($institutionId);

        if ($institution->getAttribute('allow_public_event_submission')) {
            return false;
        }

        if ($this->eligibilityFor($institution)->eligible) {
            return false;
        }

        $institution->forceFill([
            'allow_public_event_submission' => true,
        ])->save();

        $this->cache->forget();

        return true;
    }

    public function syncForAccount(string $accountId): int
    {
        $institutionIds = DirectoryModel::query('institution')
            ->whereKey($this->memberships->subjectIdsFor($accountId, MembershipSubjectType::Institution))
            ->where('allow_public_event_submission', false)
            ->pluck('institutions.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $reopened = 0;

        foreach ($institutionIds as $institutionId) {
            if ($this->reopenIfIneligible($institutionId)) {
                $reopened++;
            }
        }

        return $reopened;
    }

    public function sweep(): int
    {
        $reopened = 0;

        DirectoryModel::query('institution')
            ->where('allow_public_event_submission', false)
            ->each(function (Model $institution) use (&$reopened): void {
                if ($this->reopenIfIneligible((string) $institution->getKey())) {
                    $reopened++;
                }
            });

        return $reopened;
    }

    private function eligibilityFor(Model $institution): InstitutionSubmissionEligibility
    {
        $institution->loadMissing('members');

        /** @var Collection<int, Model> $members */
        $members = $institution->getRelation('members');

        if ($members->isEmpty()) {
            return InstitutionSubmissionEligibility::ineligible([
                __('Tiada ahli institusi yang didaftarkan.'),
            ]);
        }

        $hasOwnerOrAdminMember = false;

        foreach ($members as $member) {
            $role = $member->getRelationValue('pivot')?->getAttribute('role');
            $hasRole = in_array($role, ['owner', 'admin'], true);

            if (! $hasRole) {
                continue;
            }

            $hasOwnerOrAdminMember = true;

            if ($member->getAttribute('phone_verified_at') !== null) {
                return InstitutionSubmissionEligibility::eligible();
            }
        }

        if (! $hasOwnerOrAdminMember) {
            return InstitutionSubmissionEligibility::ineligible([
                __('Tiada ahli institusi dengan peranan owner/admin.'),
            ]);
        }

        return InstitutionSubmissionEligibility::ineligible([
            __('Peranan owner/admin memerlukan nombor telefon yang telah disahkan.'),
        ]);
    }

    private function institution(string $institutionId): Model
    {
        $institution = DirectoryModel::query('institution')->find($institutionId);

        if (! $institution instanceof Model) {
            throw new RuntimeException("Institution [{$institutionId}] was not found.");
        }

        return $institution;
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
