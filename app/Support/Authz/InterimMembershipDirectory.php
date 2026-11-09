<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\Organizations\Models\Organization;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipRecord;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Interim membership read adapter over the current member pivots.
 *
 * The Membership capability will own these pivots once extracted. Until then
 * this adapter is the shared read surface for membership state; it must not
 * become a second write path.
 */
final readonly class InterimMembershipDirectory implements MembershipDirectory
{
    public function subjectIdsFor(
        string $accountId,
        MembershipSubjectType $subjectType,
        ?string $role = null,
    ): array {
        $memberships = $this->membershipsQuery($accountId, $subjectType);

        if (! $memberships instanceof BelongsToMany) {
            return [];
        }

        if (is_string($role) && $role !== '') {
            $memberships->wherePivot('role', $role);
        }

        return $memberships
            ->pluck($memberships->getRelated()->getQualifiedKeyName())
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function membershipsFor(
        string $accountId,
        ?MembershipSubjectType $subjectType = null,
    ): array {
        $subjectTypes = $subjectType instanceof MembershipSubjectType
            ? [$subjectType]
            : MembershipSubjectType::cases();

        $records = [];

        foreach ($subjectTypes as $type) {
            $memberships = $this->membershipsQuery($accountId, $type);

            if (! $memberships instanceof BelongsToMany) {
                continue;
            }

            foreach ($memberships->get() as $member) {
                $role = $this->pivotRole($member);

                if ($role === null) {
                    continue;
                }

                $records[] = new MembershipRecord(
                    subjectType: $type,
                    subjectId: (string) $member->getKey(),
                    accountId: $accountId,
                    role: $role,
                );
            }
        }

        return $records;
    }

    public function members(MembershipSubjectType $subjectType, string $subjectId): array
    {
        $records = [];

        foreach ($this->subjectMembersQuery($subjectType, $subjectId)->get() as $member) {
            $role = $this->pivotRole($member);

            if ($role === null) {
                continue;
            }

            $records[] = new MembershipRecord(
                subjectType: $subjectType,
                subjectId: $subjectId,
                accountId: (string) $member->getKey(),
                role: $role,
            );
        }

        return $records;
    }

    public function hasRole(
        string $accountId,
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $role,
    ): bool {
        return $this->hasAnyRole($accountId, $subjectType, $subjectId, [$role]);
    }

    public function hasAnyRole(
        string $accountId,
        MembershipSubjectType $subjectType,
        string $subjectId,
        array $roles,
    ): bool {
        $members = $this->subjectMembersQuery($subjectType, $subjectId);
        $roles = $this->normalizeRoles($roles);

        if ($roles === []) {
            return false;
        }

        return $members
            ->whereKey($accountId)
            ->wherePivotIn('role', $roles)
            ->exists();
    }

    public function hasAnyMembershipRole(
        string $accountId,
        MembershipSubjectType $subjectType,
        array $roles,
    ): bool {
        $memberships = $this->membershipsQuery($accountId, $subjectType);
        $roles = $this->normalizeRoles($roles);

        if (! $memberships instanceof BelongsToMany || $roles === []) {
            return false;
        }

        return $memberships
            ->wherePivotIn('role', $roles)
            ->exists();
    }

    /**
     * @return BelongsToMany<Model, User, Pivot, 'pivot'>|null
     */
    private function membershipsQuery(string $accountId, MembershipSubjectType $subjectType): ?BelongsToMany
    {
        $relation = $this->relationFor($subjectType);
        $account = new User;
        $account->setAttribute($account->getKeyName(), $accountId);

        if (! method_exists($account, $relation)) {
            return null;
        }

        /** @var BelongsToMany<Model, User, Pivot, 'pivot'> $memberships */
        $memberships = $account->{$relation}();

        return $memberships;
    }

    /**
     * @return BelongsToMany<Model, User, Pivot, 'pivot'>
     */
    private function subjectMembersQuery(
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): BelongsToMany {
        $subject = match ($subjectType) {
            MembershipSubjectType::Institution => new Institution,
            MembershipSubjectType::Person => new Person,
            MembershipSubjectType::Event => new Event,
            MembershipSubjectType::Reference => new Reference,
            MembershipSubjectType::Organization => new Organization,
        };

        $subject->setAttribute($subject->getKeyName(), $subjectId);

        /** @var BelongsToMany<Model, User, Pivot, 'pivot'> $members */
        $members = $subject->members();

        return $members;
    }

    /**
     * @param  array<int, mixed>  $roles
     * @return list<string>
     */
    private function normalizeRoles(array $roles): array
    {
        return array_values(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && $role !== '',
        ));
    }

    private function relationFor(MembershipSubjectType $subjectType): string
    {
        return match ($subjectType) {
            MembershipSubjectType::Institution => 'institutions',
            MembershipSubjectType::Person => 'persons',
            MembershipSubjectType::Event => 'memberEvents',
            MembershipSubjectType::Reference => 'references',
            MembershipSubjectType::Organization => 'organizations',
        };
    }

    private function pivotRole(Model $member): ?string
    {
        $pivot = $member->getRelationValue('pivot');

        if (! $pivot instanceof Pivot) {
            return null;
        }

        $role = $pivot->getAttribute('role');

        return is_string($role) && $role !== '' ? $role : null;
    }
}
