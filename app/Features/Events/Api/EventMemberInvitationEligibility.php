<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Shared\Auth\AccountActor;

interface EventMemberInvitationEligibility
{
    public function canInvite(AccountActor $actor, string $eventId): bool;
}
