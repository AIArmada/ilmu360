<?php

declare(strict_types=1);

namespace App\Shared\Membership;

/**
 * Scalar permission boundary for membership-aware authorization.
 *
 * The root MemberPermissionGate implements this contract with its existing
 * role thresholds and subject-specific rules, so the Membership feature can
 * own the public seam without importing root models or the gate itself.
 */
interface MembershipPermissionEvaluator
{
    public function canOnSubject(
        string $accountId,
        string $permission,
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): bool;

    /**
     * Event permission granted through a person membership and a key-person link.
     */
    public function canThroughPersonSubject(
        string $accountId,
        string $permission,
        string $eventId,
    ): bool;

    public function hasAnyOnSubjectType(
        string $accountId,
        string $permission,
        MembershipSubjectType $subjectType,
    ): bool;

    /**
     * Account identifiers holding a permission on the subject.
     *
     * @return list<string>
     */
    public function memberAccountIdsWithPermission(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $permission,
    ): array;
}
