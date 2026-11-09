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
        $subject = $this->subject($subjectType, $subjectId);

        if (! $subject instanceof Model) {
            return [];
        }

        $records = [];

        foreach ($subject->members()->get() as $member) {
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
        $subject = $this->subject($subjectType, $subjectId);

        if (! $subject instanceof Model) {
            return false;
        }

        $roles = array_values(array_filter(
            $roles,
            static fn (mixed $role): bool => is_string($role) && $role !== '',
        ));

        if ($roles === []) {
            return false;
        }

        return $subject->members()
            ->whereKey($accountId)
            ->wherePivotIn('role', $roles)
            ->exists();
    }

    /**
     * @return BelongsToMany<Model, User, Pivot, 'pivot'>|null
     */
    private function membershipsQuery(string $accountId, MembershipSubjectType $subjectType): ?BelongsToMany
    {
        $account = User::query()->find($accountId);

        if (! $account instanceof User) {
            return null;
        }

        $relation = $this->relationFor($subjectType);

        if (! method_exists($account, $relation)) {
            return null;
        }

        /** @var BelongsToMany<Model, User, Pivot, 'pivot'> $memberships */
        $memberships = $account->{$relation}();

        return $memberships;
    }

    private function subject(
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): Institution|Person|Event|Reference|Organization|null {
        return match ($subjectType) {
            MembershipSubjectType::Institution => Institution::query()->find($subjectId),
            MembershipSubjectType::Person => Person::query()->find($subjectId),
            MembershipSubjectType::Event => Event::query()->find($subjectId),
            MembershipSubjectType::Reference => Reference::query()->find($subjectId),
            MembershipSubjectType::Organization => Organization::query()->find($subjectId),
        };
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
