<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use AIArmada\Addressing\Features\Geography\Actions\SaveAddressAreaAction;
use AIArmada\FilamentAddressing\Resources\AddressAreaResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;

/**
 * Package-owned definition for the Addressing address-area resource.
 */
final class AddressAreaResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        SaveAddressAreaAction $saveAddressAreaAction,
        AdminMutationHookRegistry $hooks,
    ) {
        parent::__construct(
            $context,
            AddressAreaResource::class,
            new AddressAreaAdminResourceWriter(
                $context,
                AddressAreaResource::class,
                $saveAddressAreaAction,
                $hooks,
            ),
        );
    }
}
