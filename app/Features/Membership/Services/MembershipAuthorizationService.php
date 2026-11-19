<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use App\Features\Membership\Api\MembershipAuthorization;
use App\Features\Membership\Api\MemberSubjectType;
use App\Shared\Membership\MembershipPermissionEvaluator;

/**
 * Scalar membership authorization boundary.
 *
 * MemberPermissionGate remains the root adapter and the single source of role
 * thresholds; this service only adapts it to the Shared contract.
 */
final readonly class MembershipAuthorizationService implements MembershipAuthorization
{
    public function __construct(
        private MembershipPermissionEvaluator $evaluator,
    ) {}

    public function can(
        string $accountId,
        string $permission,
        MemberSubjectType $subjectType,
        string $subjectId,
    ): bool {
        return $this->evaluator->canOnSubject($accountId, $permission, $subjectType, $subjectId);
    }

    public function canThroughPerson(string $accountId, string $permission, string $eventId): bool
    {
        return $this->evaluator->canThroughPersonSubject($accountId, $permission, $eventId);
    }

    public function hasAny(
        string $accountId,
        string $permission,
        MemberSubjectType $subjectType,
    ): bool {
        return $this->evaluator->hasAnyOnSubjectType($accountId, $permission, $subjectType);
    }

    public function membersWithPermission(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $permission,
    ): array {
        return $this->evaluator->memberAccountIdsWithPermission($subjectType, $subjectId, $permission);
    }
}
