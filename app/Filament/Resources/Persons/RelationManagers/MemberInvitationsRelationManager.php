<?php

namespace App\Filament\Resources\Persons\RelationManagers;

use App\Enums\MemberSubjectType;
use App\Filament\RelationManagers\MemberInvitationsRelationManager as BaseMemberInvitationsRelationManager;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;

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
