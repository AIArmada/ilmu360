<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

interface MembershipAuthorization
{
    public function can(
        string $accountId,
        string $permission,
        MemberSubjectType $subjectType,
        string $subjectId,
    ): bool;

    /**
     * Event permission granted through a person membership and a key-person link.
     */
    public function canThroughPerson(string $accountId, string $permission, string $eventId): bool;

    public function hasAny(
        string $accountId,
        string $permission,
        MemberSubjectType $subjectType,
    ): bool;

    /**
     * Account identifiers holding a permission on the subject.
     *
     * @return list<string>
     */
    public function membersWithPermission(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $permission,
    ): array;
}
