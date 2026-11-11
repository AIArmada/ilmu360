<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Membership\MembershipSubjectType;

/**
 * Read boundary for membership invitations.
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
}
