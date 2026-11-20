<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Api\Member;

use App\Features\References\EntryPoints\Admin\Member\ReferenceMemberResourceHooks;
use App\Features\References\EntryPoints\Filament\Ahli\Resources\References\ReferenceResource as AhliReferenceResource;
use App\Platform\Api\Member\AbstractMemberResourceDefinition;
use App\Platform\Api\Member\MemberResourceContext;

final class ReferenceResourceDefinition extends AbstractMemberResourceDefinition
{
    public function __construct(
        MemberResourceContext $members,
        ReferenceMemberResourceHooks $hooks,
    ) {
        parent::__construct($members, $hooks, AhliReferenceResource::class);
    }
}
