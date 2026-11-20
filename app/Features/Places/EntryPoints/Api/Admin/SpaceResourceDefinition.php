<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Api\Admin;

use App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\HookedAdminResourceWriter;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class SpaceResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        AdminMutationHookRegistry $hooks,
    ) {
        parent::__construct(
            $context,
            SpaceResource::class,
            new HookedAdminResourceWriter($context, SpaceResource::class, $hooks),
        );
    }
}
