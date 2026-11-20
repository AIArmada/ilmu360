<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Api\Admin;

use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

/**
 * Read-only admin adapter for membership applications; review flows stay in
 * the Membership workflow endpoints.
 */
final class MembershipApplicationResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(ResourceDefinitionContext $context)
    {
        parent::__construct($context, MembershipApplicationResource::class);
    }
}
