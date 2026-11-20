<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\EntryPoints\Api\Admin;

use App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\SlugRedirectResource;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class SlugRedirectResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(ResourceDefinitionContext $context)
    {
        parent::__construct($context, SlugRedirectResource::class);
    }
}
