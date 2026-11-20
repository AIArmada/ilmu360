<?php

declare(strict_types=1);

namespace App\Platform\Auditing\EntryPoints\Api\Admin;

use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use App\Platform\Auditing\EntryPoints\Filament\Resources\Audits\AuditResource;

final class AuditResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(ResourceDefinitionContext $context)
    {
        parent::__construct($context, AuditResource::class);
    }
}
