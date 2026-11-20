<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\Api\Admin;

use App\Features\Reports\Api\Orm\ReportAdminContract;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;

final class ReportResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        ReportAdminContract $reportAdmin,
        AdminMutationHookRegistry $hooks,
    ) {
        parent::__construct(
            $context,
            ReportResource::class,
            new ReportAdminResourceWriter($context, ReportResource::class, $reportAdmin, $hooks),
        );
    }
}
