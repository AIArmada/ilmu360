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
use Illuminate\Database\Eloquent\Model;

final readonly class MemberInvitationAccessAdapter implements MemberInvitationAccess
{
    public function __construct(
        private MemberInvitationGate $gate,
    ) {}

    public function canInvite(AccountActor $actor, Institution|Person|Event|Reference|Organization $subject): bool
    {
        return $this->gate->canInvite($actor, $subject);
    }

    public function canInviteOwnerRecord(AccountActor $actor, Model $ownerRecord): bool
    {
        $subject = match (true) {
            $ownerRecord instanceof Institution,
            $ownerRecord instanceof Person,
            $ownerRecord instanceof Event,
            $ownerRecord instanceof Reference,
            $ownerRecord instanceof Organization => $ownerRecord,
            default => null,
        };

        return $subject !== null && $this->gate->canInvite($actor, $subject);
    }
}
