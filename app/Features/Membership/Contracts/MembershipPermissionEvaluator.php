<?php

declare(strict_types=1);

namespace App\Features\Membership\Contracts;

use App\Features\Membership\Api\MemberSubjectType;

/**
 * Scalar permission boundary for membership-aware authorization.
 *
 * MemberPermissionGate implements this contract with its existing role
 * thresholds and subject-specific rules, so authorization callers depend on
 * the scalar seam instead of the gate itself.
 */
interface MembershipPermissionEvaluator
{
    public function canOnSubject(
        string $accountId,
        string $permission,
        MemberSubjectType $subjectType,
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
        MemberSubjectType $subjectType,
    ): bool;

    /**
     * Account identifiers holding a permission on the subject.
     *
     * @return list<string>
     */
    public function memberAccountIdsWithPermission(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $permission,
    ): array;
}
