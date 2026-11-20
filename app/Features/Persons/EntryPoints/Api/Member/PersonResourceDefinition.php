<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Api\Member;

use App\Features\Persons\EntryPoints\Admin\Member\PersonMemberResourceHooks;
use App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\PersonResource as AhliPersonResource;
use App\Platform\Api\Member\AbstractMemberResourceDefinition;
use App\Platform\Api\Member\MemberResourceContext;

final class PersonResourceDefinition extends AbstractMemberResourceDefinition
{
    public function __construct(
        MemberResourceContext $members,
        PersonMemberResourceHooks $hooks,
    ) {
        parent::__construct($members, $hooks, AhliPersonResource::class);
    }
}
