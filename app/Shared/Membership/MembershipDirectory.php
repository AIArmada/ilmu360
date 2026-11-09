<?php

declare(strict_types=1);

namespace App\Shared\Membership;

interface MembershipDirectory
{
    /**
     * Membership subject identifiers owned by the account.
     *
     * @return list<string>
     */
    public function subjectIdsFor(
        string $accountId,
        MembershipSubjectType $subjectType,
        ?string $role = null,
    ): array;

    /**
     * Membership rows for the account, optionally narrowed to one subject type.
     *
     * @return list<MembershipRecord>
     */
    public function membershipsFor(
        string $accountId,
        ?MembershipSubjectType $subjectType = null,
    ): array;

    /**
     * Current members of one subject.
     *
     * @return list<MembershipRecord>
     */
    public function members(MembershipSubjectType $subjectType, string $subjectId): array;

    public function hasRole(
        string $accountId,
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $role,
    ): bool;

    /**
     * @param  list<string>  $roles
     */
    public function hasAnyRole(
        string $accountId,
        MembershipSubjectType $subjectType,
        string $subjectId,
        array $roles,
    ): bool;
}
