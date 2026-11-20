<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Filament\Support;

use AIArmada\Organizations\Models\Organization;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\Orm\Filament\MemberInvitationAccess;
use App\Features\Membership\Services\MemberInvitationGate;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Shared\Auth\AccountActor;

final readonly class MemberInvitationAccessAdapter implements MemberInvitationAccess
{
    public function __construct(
        private MemberInvitationGate $gate,
    ) {}

    public function canInvite(AccountActor $actor, object $subject): bool
    {
        if (! $subject instanceof Institution
            && ! $subject instanceof Person
            && ! $subject instanceof Event
            && ! $subject instanceof Reference
            && ! $subject instanceof Organization) {
            return false;
        }

        return $this->gate->canInvite($actor, $subject);
    }
}
