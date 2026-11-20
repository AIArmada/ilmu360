<?php

declare(strict_types=1);

namespace App\Features\Contributions\EntryPoints\Api\Admin;

use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

/**
 * Read-only admin adapter for contribution requests; review flows stay in the
 * Contributions workflow endpoints.
 */
final class ContributionRequestResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(ResourceDefinitionContext $context)
    {
        parent::__construct($context, ContributionRequestResource::class);
    }
}
