<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * Keyed lookup for owner definitions without importing any concrete resource.
 */
final readonly class ResourceDefinitionDirectory
{
    public function __construct(
        private ResourceDefinitionRegistry $definitions,
        private ResourceIntrospection $introspection,
    ) {}

    /** @param class-string<resource> $resourceClass */
    public function keyFor(string $resourceClass): string
    {
        return $this->introspection->keyFor($resourceClass);
    }

    /** @param class-string<resource> $resourceClass */
    public function definitionForClass(string $resourceClass): ?FilamentBackedResourceDefinition
    {
        foreach ($this->definitions->all(ResourceSurface::Admin) as $definition) {
            if ($definition instanceof FilamentBackedResourceDefinition && $definition->resourceClass() === $resourceClass) {
                return $definition;
            }
        }

        $definition = $this->definitions->resolve(ResourceSurface::Admin, $this->keyFor($resourceClass));

        return $definition instanceof FilamentBackedResourceDefinition ? $definition : null;
    }

    /**
     * Mirrors the legacy registry's first-match model lookup.
     *
     * @param  class-string<Model>  $modelClass
     * @return class-string<resource>|null
     */
    public function resourceClassForModel(string $modelClass): ?string
    {
        foreach ($this->definitions->all(ResourceSurface::Admin) as $definition) {
            if (! $definition instanceof FilamentBackedResourceDefinition) {
                continue;
            }

            $resourceClass = $definition->resourceClass();
            $resourceModel = $resourceClass::getModel();

            if ($resourceModel === $modelClass || is_subclass_of($modelClass, $resourceModel)) {
                return $resourceClass;
            }
        }

        return null;
    }

    /** @param class-string<resource> $resourceClass */
    public function definition(string $resourceClass): ?FilamentBackedResourceDefinition
    {
        return $this->definitionForClass($resourceClass);
    }
}
