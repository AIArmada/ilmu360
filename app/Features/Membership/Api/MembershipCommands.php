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
     * Remove an app-owned member pivot without revoking scoped roles.
     *
     * @throws \InvalidArgumentException when the subject is an Organization
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
