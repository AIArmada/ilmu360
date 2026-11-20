<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Api\Admin;

use App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\HookedAdminResourceWriter;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class VenueResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        AdminMutationHookRegistry $hooks,
    ) {
        parent::__construct(
            $context,
            VenueResource::class,
            new HookedAdminResourceWriter($context, VenueResource::class, $hooks),
        );
    }
}
