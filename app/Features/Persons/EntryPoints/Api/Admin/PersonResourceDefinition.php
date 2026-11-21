<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Api\Admin;

use App\Features\Contributions\Api\ContributionEntityState;
use App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\HookedAdminResourceWriter;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use Illuminate\Database\Eloquent\Model;

final class PersonResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        AdminMutationHookRegistry $hooks,
        ContributionEntityState $contributionState,
    ) {
        parent::__construct(
            $context,
            PersonResource::class,
            new HookedAdminResourceWriter(
                $context,
                PersonResource::class,
                $hooks,
                static fn (Model $record): array => $record->getMorphClass() === 'person'
                    ? $contributionState->stateFor('person', (string) $record->getKey())
                    : [],
            ),
        );
    }
}
