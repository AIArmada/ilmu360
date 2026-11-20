<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Api\Admin;

use App\Features\Contributions\Api\ContributionEntityState;
use App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\ContributionStateAdminResourceWriter;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class ReferenceResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        AdminMutationHookRegistry $hooks,
        ContributionEntityState $contributionState,
    ) {
        parent::__construct(
            $context,
            ReferenceResource::class,
            new ContributionStateAdminResourceWriter(
                $context,
                ReferenceResource::class,
                $hooks,
                $contributionState,
                'reference',
            ),
        );
    }
}
