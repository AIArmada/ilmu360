<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Api\Admin;

use App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\HookedAdminResourceWriter;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class SeriesResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        AdminMutationHookRegistry $hooks,
    ) {
        parent::__construct(
            $context,
            SeriesResource::class,
            new HookedAdminResourceWriter($context, SeriesResource::class, $hooks),
        );
    }
}
