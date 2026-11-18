<?php

namespace App\Features\References\EntryPoints\Filament\Resources\References\RelationManagers;

use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\EntryPoints\Filament\RelationManagers\MemberInvitationsRelationManager as BaseMemberInvitationsRelationManager;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;

class MemberInvitationsRelationManager extends BaseMemberInvitationsRelationManager
{
    protected function getSubjectType(): MemberSubjectType
    {
        return MemberSubjectType::Reference;
    }

    protected function getSubjectOwner(): Institution|Person|Event|Reference
    {
        /** @var Reference $reference */
        $reference = $this->getOwnerRecord();

        return $reference;
    }
}
