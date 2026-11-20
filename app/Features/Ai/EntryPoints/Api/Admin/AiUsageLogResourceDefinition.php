<?php

declare(strict_types=1);

namespace App\Features\Ai\EntryPoints\Api\Admin;

use App\Features\Ai\EntryPoints\Filament\Resources\AiUsageLogs\AiUsageLogResource;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class AiUsageLogResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(ResourceDefinitionContext $context)
    {
        parent::__construct($context, AiUsageLogResource::class);
    }
}
