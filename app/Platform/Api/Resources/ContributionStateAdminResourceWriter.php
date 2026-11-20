<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use App\Features\Contributions\Api\ContributionEntityState;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * Hooked writer that injects the owner's contribution-state projection as the
 * record defaults for the given persisted morph alias.
 */
final class ContributionStateAdminResourceWriter extends HookedAdminResourceWriter
{
    /** @param class-string<resource> $resourceClass */
    public function __construct(
        ResourceDefinitionContext $context,
        string $resourceClass,
        AdminMutationHookRegistry $hooks,
        ContributionEntityState $contributionState,
        string $entityType,
    ) {
        parent::__construct(
            $context,
            $resourceClass,
            $hooks,
            static fn (Model $record): array => $record->getMorphClass() === $entityType
                ? $contributionState->stateFor($entityType, (string) $record->getKey())
                : [],
        );
    }
}
