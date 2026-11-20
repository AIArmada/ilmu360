<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Api\Member;

use App\Features\Institutions\EntryPoints\Admin\Member\InstitutionMemberResourceHooks;
use App\Features\Institutions\EntryPoints\Filament\Ahli\Resources\Institutions\InstitutionResource as AhliInstitutionResource;
use App\Platform\Api\Member\AbstractMemberResourceDefinition;
use App\Platform\Api\Member\MemberResourceContext;

final class InstitutionResourceDefinition extends AbstractMemberResourceDefinition
{
    public function __construct(
        MemberResourceContext $members,
        InstitutionMemberResourceHooks $hooks,
    ) {
        parent::__construct($members, $hooks, AhliInstitutionResource::class);
    }
}
