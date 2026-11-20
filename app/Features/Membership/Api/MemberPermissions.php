<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Auth\AccountActor;

interface MemberPermissions
{
    public function hasAnyInstitutionPermission(AccountActor $user, string $permission): bool;

    public function hasAnyEventPermission(AccountActor $user, string $permission): bool;

    public function hasAnyPersonPermission(AccountActor $user, string $permission): bool;

    public function hasAnyOrganizationPermission(AccountActor $user, string $permission): bool;

    public function hasAnyReferencePermission(AccountActor $user, string $permission): bool;

    /**
     * Subject-scoped permission check for any member subject type. Event
     * checks here are direct event membership only; use
     * canThroughPersonSubject() when person-scoped event access is allowed.
     */
    public function canSubject(
        AccountActor $user,
        string $permission,
        MemberSubjectType $subjectType,
        string $subjectId,
    ): bool;

    /**
     * Person-scoped event permission: the account must hold an eligible
     * person membership for one of the event's persons.
     */
    public function canThroughPersonSubject(string $accountId, string $permission, string $eventId): bool;

    /**
     * Account ids holding the permission on the given subject.
     *
     * @return list<string>
     */
    public function subjectMembersWithPermission(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $permission,
    ): array;
}
