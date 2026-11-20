<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Api\Admin;

use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use Filament\Resources\Resource;

/**
 * Read-only owner adapter for Events-owned package Filament resources
 * (occurrences, sessions, registrations, attendance, changes, templates and
 * taxonomy metadata).
 */
final class EventsPackageResourceDefinition extends FilamentResourceDefinition
{
    /** @param class-string<resource> $resourceClass */
    public function __construct(ResourceDefinitionContext $context, string $resourceClass)
    {
        parent::__construct($context, $resourceClass);
    }
}
