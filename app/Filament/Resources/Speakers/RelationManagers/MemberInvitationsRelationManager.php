<?php

namespace App\Filament\Resources\Speakers\RelationManagers;

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
        return MemberSubjectType::Speaker;
    }

    protected function getSubjectOwner(): Institution|Speaker|Event|Reference
    {
        /** @var Speaker $speaker */
        $speaker = $this->getOwnerRecord();

        return $speaker;
    }
}
