<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Membership\MembershipSubjectType;

/**
 * Scalar snapshot of one membership invitation.
 */
final readonly class MembershipInvitationDetail
{
    public function __construct(
        public string $id,
        public MembershipSubjectType $subjectType,
        public string $subjectId,
        public string $email,
        public string $role,
        public string $status,
        public ?string $invitedBy = null,
        public ?string $expiresAt = null,
        public ?string $acceptedAt = null,
        public ?string $createdAt = null,
        public bool $isValid = false,
    ) {}
}
