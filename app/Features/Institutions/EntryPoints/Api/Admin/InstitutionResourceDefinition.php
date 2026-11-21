<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Api\Admin;

use App\Features\Contributions\Api\ContributionEntityState;
use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\InstitutionResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\HookedAdminResourceWriter;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use Illuminate\Database\Eloquent\Model;

final class InstitutionResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        AdminMutationHookRegistry $hooks,
        ContributionEntityState $contributionState,
    ) {
        parent::__construct(
            $context,
            InstitutionResource::class,
            new HookedAdminResourceWriter(
                $context,
                InstitutionResource::class,
                $hooks,
                static fn (Model $record): array => $record->getMorphClass() === 'institution'
                    ? $contributionState->stateFor('institution', (string) $record->getKey())
                    : [],
            ),
        );
    }
}
