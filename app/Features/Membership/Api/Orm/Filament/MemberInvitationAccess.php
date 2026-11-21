<?php

declare(strict_types=1);

namespace App\Features\Membership\Api\Orm\Filament;

use AIArmada\Organizations\Models\Organization;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Shared\Auth\AccountActor;
use Illuminate\Database\Eloquent\Model;

/**
 * Member invitation eligibility boundary for relation-manager visibility.
 *
 * @internal Explicit ORM integration port for Filament form/relation state.
 *
 * Consumers: owning Filament resources and relation managers.
 */
interface MemberInvitationAccess
{
    public function canInvite(AccountActor $actor, Institution|Person|Event|Reference|Organization $subject): bool;

    /**
     * Narrow a Filament owner record to a supported invitation subject.
     */
    public function canInviteOwnerRecord(AccountActor $actor, Model $ownerRecord): bool;
}
