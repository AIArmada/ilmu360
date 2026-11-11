<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Membership\MembershipSubjectType;

/**
 * Write boundary for membership rows and invitation lifecycle.
 *
 * Implementations delegate to the canonical package actions; application
 * submission and review stay in MembershipApplications until the M slices move
 * their writers.
 */
interface MembershipCommands
{
    public function addMember(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $accountId,
        string $role,
    ): void;

    public function changeRole(
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
