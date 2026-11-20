<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Api\Member;

use App\Features\Events\EntryPoints\Admin\Member\EventMemberResourceHooks;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Platform\Api\Member\AbstractMemberResourceDefinition;
use App\Platform\Api\Member\MemberResourceContext;

final class EventResourceDefinition extends AbstractMemberResourceDefinition
{
    public function __construct(
        MemberResourceContext $members,
        EventMemberResourceHooks $hooks,
    ) {
        parent::__construct($members, $hooks, EventResource::class);
    }
}
