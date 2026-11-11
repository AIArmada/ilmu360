<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Membership\MembershipSubjectType;

/**
 * Write boundary for membership rows.
 *
 * Implementations resolve scalar identifiers to the owning models and delegate
 * role mutations to the canonical package actions. Invitation lifecycle writes
 * belong to MembershipInvitations.
 */
interface MembershipCommands
{
    public function assignOwner(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $accountId,
    ): void;

    public function addMember(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $accountId,
        string $role,
    ): void;

    public function changeMemberRole(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $accountId,
        string $role,
    ): void;

    public function removeMember(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $accountId,
    ): void;

    /**
     * Remove every member pivot for one subject without revoking scoped roles.
     */
    public function removeAllMembers(
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): void;

    public function transferPersonOwnership(
        string $personId,
        string $actorId,
        string $newOwnerId,
    ): void;
}
