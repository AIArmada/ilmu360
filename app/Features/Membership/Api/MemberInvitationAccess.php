<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Auth\AccountActor;

interface MemberInvitationAccess
{
    public function canInvite(AccountActor $actor, MemberSubjectType $subjectType, string $subjectId): bool;
}
