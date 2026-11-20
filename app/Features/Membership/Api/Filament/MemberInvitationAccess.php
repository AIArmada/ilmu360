<?php

declare(strict_types=1);

namespace App\Features\Membership\Api\Filament;

use App\Shared\Auth\AccountActor;

/**
 * Member invitation eligibility boundary for relation-manager visibility.
 */
interface MemberInvitationAccess
{
    public function canInvite(AccountActor $actor, object $subject): bool;
}
