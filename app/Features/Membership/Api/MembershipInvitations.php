<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Membership\MembershipSubjectType;

/**
 * Boundary for membership invitations.
 *
 * The package InviteMemberAction, AcceptInvitationAction and
 * RevokeInvitationAction remain the canonical external writers. This boundary
 * owns the app-side invite notification, duplicate/owner-role guards and the
 * expiry/status checks around acceptance.
 */
interface MembershipInvitations
{
    public function find(string $invitationId): ?MembershipInvitationDetail;

    public function findByToken(string $rawToken): ?MembershipInvitationDetail;

    /**
     * Pending, unexpired invitations for one subject.
     *
     * @return list<MembershipInvitationDetail>
     */
    public function pendingForSubject(
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): array;

    /**
     * Invitations for one subject ordered newest first.
     *
     * @return list<MembershipInvitationDetail>
     */
    public function recentForSubject(
        MembershipSubjectType $subjectType,
        string $subjectId,
        int $limit = 20,
    ): array;

    public function pendingExistsForEmail(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $email,
    ): bool;

    /**
     * Issue an app-side invitation with the app-only invitation email.
     *
     * Rejects owner-role invitations and any pending, unexpired invitation for
     * the same subject and email address.
     */
    public function invite(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $email,
        string $role,
        string $inviterId,
    ): MembershipInvitationDetail;

    /**
     * Accept one invitation on behalf of an account.
     *
     * Delegates the lifecycle transition and membership grant to the canonical
     * package action after app-side expiry, status, email and owner-role guards.
     */
    public function accept(string $invitationId, string $accountId): MembershipInvitationDetail;

    /**
     * Revoke one pending invitation on behalf of an actor.
     */
    public function revoke(string $invitationId, string $actorId): void;
}
