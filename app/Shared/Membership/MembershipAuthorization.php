<?php

declare(strict_types=1);

namespace App\Shared\Membership;

interface MembershipAuthorization
{
    public function can(
        string $accountId,
        string $permission,
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): bool;

    /**
     * Event permission granted through a person membership and a key-person link.
     */
    public function canThroughPerson(string $accountId, string $permission, string $eventId): bool;

    public function hasAny(
        string $accountId,
        string $permission,
        MembershipSubjectType $subjectType,
    ): bool;

    /**
     * Account identifiers holding a permission on the subject.
     *
     * @return list<string>
     */
    public function membersWithPermission(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $permission,
    ): array;
}
