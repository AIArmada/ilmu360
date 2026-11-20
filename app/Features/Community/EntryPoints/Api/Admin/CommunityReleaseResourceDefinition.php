<?php

declare(strict_types=1);

namespace App\Features\Community\EntryPoints\Api\Admin;

use App\Features\Community\EntryPoints\Filament\Resources\CommunityReleases\CommunityReleaseResource;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class CommunityReleaseResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(ResourceDefinitionContext $context)
    {
        parent::__construct($context, CommunityReleaseResource::class);
    }
}
