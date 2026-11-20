<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Api\Admin;

use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class UserResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(ResourceDefinitionContext $context)
    {
        parent::__construct($context, UserResource::class);
    }
}
