<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

interface MembershipDirectory
{
    /**
     * Membership subject identifiers owned by the account.
     *
     * @return list<string>
     */
    public function subjectIdsFor(
        string $accountId,
        MemberSubjectType $subjectType,
        ?string $role = null,
    ): array;

    /**
     * Membership rows for the account, optionally narrowed to one subject type.
     *
     * @return list<MembershipRecord>
     */
    public function membershipsFor(
        string $accountId,
        ?MemberSubjectType $subjectType = null,
    ): array;

    /**
     * Current members of one subject.
     *
     * @return list<MembershipRecord>
     */
    public function members(MemberSubjectType $subjectType, string $subjectId): array;

    public function hasRole(
        string $accountId,
        MemberSubjectType $subjectType,
        string $subjectId,
        string $role,
    ): bool;

    /**
     * @param  list<string>  $roles
     */
    public function hasAnyRole(
        string $accountId,
        MemberSubjectType $subjectType,
        string $subjectId,
        array $roles,
    ): bool;

    /**
     * Whether the account holds any membership of this subject type with one
     * of the supplied roles, without naming a subject.
     *
     * @param  list<string>  $roles
     */
    public function hasAnyMembershipRole(
        string $accountId,
        MemberSubjectType $subjectType,
        array $roles,
    ): bool;
}
