<?php

namespace App\Features\Persons\EntryPoints\Filament\Resources\Persons\RelationManagers;

use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Platform\Admin\Filament\RelationManagers\MemberInvitationsRelationManager as BaseMemberInvitationsRelationManager;

class MemberInvitationsRelationManager extends BaseMemberInvitationsRelationManager
{
    protected function getSubjectType(): MemberSubjectType
    {
        return MemberSubjectType::Person;
    }

    protected function getSubjectOwner(): Institution|Person|Event|Reference
    {
        /** @var Person $person */
        $person = $this->getOwnerRecord();

        return $person;
    }
}
