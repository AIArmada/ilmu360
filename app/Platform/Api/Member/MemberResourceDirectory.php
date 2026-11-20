<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Platform\Api\Resources\ResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\Api\Resources\ResourceSurface;
use Filament\Resources\Resource;

/**
 * Keyed lookup over the member definitions registered in the shared registry.
 *
 * Mirrors {@see \App\Platform\Api\Resources\ResourceDefinitionDirectory} while
 * keeping the member surface separate from the admin resource class map.
 */
final readonly class MemberResourceDirectory
{
    public function __construct(
        private ResourceDefinitionRegistry $definitions,
    ) {}

    /**
     * @return list<AbstractMemberResourceDefinition>
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->definitions->all(ResourceSurface::Member),
            static fn (ResourceDefinition $definition): bool => $definition instanceof AbstractMemberResourceDefinition,
        ));
    }

    public function definition(string $resourceClass): ?AbstractMemberResourceDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->resourceClass() === $resourceClass) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    public function definitionForModel(string $modelClass): ?AbstractMemberResourceDefinition
    {
        foreach ($this->all() as $definition) {
            $resourceModel = $definition->modelClass();

            if ($resourceModel === $modelClass || is_subclass_of($modelClass, $resourceModel)) {
                return $definition;
            }
        }

        return null;
    }

    public function resolve(string $key): ?AbstractMemberResourceDefinition
    {
        $definition = $this->definitions->resolve(ResourceSurface::Member, $key);

        return $definition instanceof AbstractMemberResourceDefinition ? $definition : null;
    }

    /**
     * @return list<class-string<resource>>
     */
    public function resourceClasses(): array
    {
        $classes = [];

        foreach ($this->all() as $definition) {
            $classes[] = $definition->resourceClass();
        }

        return $classes;
    }
}
