<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

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
        MemberSubjectType $subjectType,
        string $subjectId,
        string $accountId,
    ): void;

    public function addMember(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $accountId,
        string $role,
    ): void;

    public function changeMemberRole(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $accountId,
        string $role,
    ): void;

    public function removeMember(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $accountId,
    ): void;

    /**
     * Remove an app-owned member pivot without revoking scoped roles.
     *
     * @throws \InvalidArgumentException when the subject is an Organization
     */
    public function removeAllMembers(
        MemberSubjectType $subjectType,
        string $subjectId,
    ): void;

    public function transferPersonOwnership(
        string $personId,
        string $actorId,
        string $newOwnerId,
    ): void;
}
