<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Api\Admin;

use App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\HookedAdminResourceWriter;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class DonationChannelResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        AdminMutationHookRegistry $hooks,
    ) {
        parent::__construct(
            $context,
            DonationChannelResource::class,
            new HookedAdminResourceWriter($context, DonationChannelResource::class, $hooks),
        );
    }
}
