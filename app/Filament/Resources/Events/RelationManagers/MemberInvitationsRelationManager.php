<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Enums\MemberSubjectType;
use App\Filament\RelationManagers\MemberInvitationsRelationManager as BaseMemberInvitationsRelationManager;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;

class MemberInvitationsRelationManager extends BaseMemberInvitationsRelationManager
{
    protected function getSubjectType(): MemberSubjectType
    {
        return MemberSubjectType::Event;
    }

    protected function getSubjectOwner(): Institution|Speaker|Event|Reference
    {
        /** @var Event $event */
        $event = $this->getOwnerRecord();

        return $event;
    }
}
