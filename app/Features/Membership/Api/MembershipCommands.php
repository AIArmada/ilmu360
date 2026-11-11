<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Membership\MembershipSubjectType;

/**
 * Write boundary for membership rows and invitation lifecycle.
 *
 * Implementations resolve scalar identifiers to the owning models and delegate
 * role mutations to the canonical package actions. Application submission and
 * review stay in MembershipApplications until the remaining M slices move
 * their writers.
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

    public function invite(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $email,
        string $role,
        string $inviterId,
    ): MembershipInvitationDetail;

    public function revokeInvitation(string $invitationId, string $actorId): void;

    public function acceptInvitation(string $rawToken, string $accountId): void;
}
