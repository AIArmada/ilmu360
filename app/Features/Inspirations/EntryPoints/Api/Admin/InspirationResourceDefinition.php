<?php

declare(strict_types=1);

namespace App\Features\Inspirations\EntryPoints\Api\Admin;

use App\Features\Inspirations\Api\InspirationAdminContract;
use App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\InspirationResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class InspirationResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        InspirationAdminContract $inspirationAdmin,
        AdminMutationHookRegistry $hooks,
    ) {
        parent::__construct(
            $context,
            InspirationResource::class,
            new InspirationAdminResourceWriter($context, InspirationResource::class, $inspirationAdmin, $hooks),
        );
    }
}
