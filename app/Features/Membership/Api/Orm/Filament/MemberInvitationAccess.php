<?php

declare(strict_types=1);

namespace App\Features\Membership\Api\Orm\Filament;

use App\Shared\Auth\AccountActor;

/**
 * Member invitation eligibility boundary for relation-manager visibility.
 *
 * @internal Explicit ORM integration port for Filament form/relation state.
 *
 * Consumers: owning Filament resources and relation managers.
 */
interface MemberInvitationAccess
{
    public function canInvite(AccountActor $actor, object $subject): bool;
}
