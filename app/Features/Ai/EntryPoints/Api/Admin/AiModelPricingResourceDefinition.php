<?php

declare(strict_types=1);

namespace App\Features\Ai\EntryPoints\Api\Admin;

use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\AiModelPricingResource;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class AiModelPricingResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(ResourceDefinitionContext $context)
    {
        parent::__construct($context, AiModelPricingResource::class);
    }
}
