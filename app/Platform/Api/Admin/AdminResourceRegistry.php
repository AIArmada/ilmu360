<?php

namespace App\Platform\Api\Admin;

use App\Platform\Admin\ExcludedFromAdminApi;
use App\Platform\Api\Resources\AbstractAdminResourceWriter;
use App\Platform\Api\Resources\EloquentResourceQuery;
use App\Platform\Api\Resources\EloquentResourceSerializer;
use App\Platform\Api\Resources\ResourceActorResolver;
use App\Platform\Api\Resources\ResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionDirectory;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\Api\Resources\ResourceIntrospection;
use App\Platform\Api\Resources\ResourceSurface;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Ordered admin resource discovery plus thin delegation to the registered
 * owner definitions.
 *
 * @phpstan-type AdminResourceMeta array{
 *   key: string,
 *   resource_class: class-string<Resource>,
 *   model_class: class-string<Model>,
 *   model_label: string,
 *   plural_model_label: string,
 *   navigation_group: string|null,
 *   record_title_attribute: string|null,
 *   pages: array<string, bool>,
 *   relations: list<string>,
 *   abilities: array<string, bool>,
 *   filters: list<array{key: string, label: string, type: string, options?: array<string, string>}>,
 *   write_support: array<string, bool>,
 *   api_routes: array<string, string|null>,
 *   mcp_tools: array<string, mixed>,
 *   panel_routes: array<string, string|null>,
 *   timezone_sensitive: bool,
 *   date_semantics: array<string, mixed>|null
 * }
 */
class AdminResourceRegistry
{
    /** @var list<class-string<resource>>|null */
    private ?array $resourcesCache = null;

    /** @var list<class-string<resource>>|null */
    private ?array $accessibleResourcesCache = null;

    public function __construct(
        private readonly ResourceDefinitionRegistry $definitions,
        private readonly ResourceActorResolver $actors,
        private readonly EloquentResourceQuery $queries,
        private readonly EloquentResourceSerializer $serializer,
        private readonly ResourceIntrospection $introspection,
        private readonly AdminResourceHookRegistry $hooks,
        private readonly ResourceDefinitionDirectory $directory,
    ) {}

    /** @return list<class-string<resource>> */
    public function resources(): array
    {
        if (is_array($this->resourcesCache)) {
            return $this->resourcesCache;
        }

        /** @var array<int|string, class-string<resource>> $resources */
        $resources = Filament::getPanel('admin')->getResources();

        return $this->resourcesCache = array_values(array_filter(
            array_values($resources),
            static fn (string $resource): bool => ! is_subclass_of($resource, ExcludedFromAdminApi::class),
        ));
    }

    /** @return list<class-string<resource>> */
    public function accessibleResources(): array
    {
        if (is_array($this->accessibleResourcesCache)) {
            return $this->accessibleResourcesCache;
        }

        return $this->accessibleResourcesCache = array_values(array_filter(
            $this->resources(),
            $this->canAccessResource(...),
        ));
    }

    public function resolve(string $resourceKey): ?string
    {
        foreach ($this->resources() as $resourceClass) {
            if ($this->keyFor($resourceClass) === $resourceKey) {
                return $resourceClass;
            }
        }

        return null;
    }

    /** @param class-string<Model> $modelClass */
    public function resolveForModel(string $modelClass): ?string
    {
        foreach ($this->resources() as $resourceClass) {
            $resourceModel = $resourceClass::getModel();
            if ($resourceModel === $modelClass || is_subclass_of($modelClass, $resourceModel)) {
                return $resourceClass;
            }
        }

        return null;
    }

    public function canAccessResource(string $resourceClass): bool
    {
        return $this->definition($resourceClass)->canAccess($this->actors->current());
    }

    /** @return AdminResourceMeta */
    public function metadata(string $resourceClass): array
    {
        return $this->definition($resourceClass)->metadata($this->actors->current());
    }

    /** @return Builder<Model> */
    public function queryFor(string $resourceClass): Builder
    {
        return $this->queries->forResource($resourceClass);
    }

    public function resolveRecord(string $resourceClass, string $recordKey): Model
    {
        return $this->queries->resolveRecord($resourceClass, $recordKey);
    }

    /** @return array<string, bool> */
    public function recordAbilities(string $resourceClass, Model $record): array
    {
        return $this->serializer->recordAbilities(
            $resourceClass,
            $record,
            $this->actors->user($this->actors->current()),
        );
    }

    public function keyFor(string $resourceClass): string
    {
        return $this->introspection->keyFor($resourceClass);
    }

    public function hooksFor(string $resourceClass): ?Contracts\AdminResourceHooks
    {
        return $this->hooks->for($resourceClass);
    }

    /** @return list<array{key: string, label: string, type: string, options?: array<string, string>}> */
    public function filters(string $resourceClass): array
    {
        return $this->definition($resourceClass)->filters($this->actors->current());
    }

    /** @return list<array{key: string, label: string, type: string, options?: array<string, string>}> */
    public function filterMetadata(string $resourceClass): array
    {
        return array_map(
            static fn (array $filter): array => Arr::only($filter, ['key', 'label', 'type', 'options']),
            $this->filters($resourceClass),
        );
    }

    /** @return array<string, mixed> */
    public function serializeRecord(string $resourceClass, Model $record): array
    {
        return $this->serializer->serializeRecord(
            $resourceClass,
            $record,
            $this->actors->user($this->actors->current()),
        );
    }

    /** @return array<string, mixed> */
    public function serializeRecordDetail(string $resourceClass, Model $record): array
    {
        return $this->serializer->serializeRecordDetail(
            $resourceClass,
            $record,
            $this->actors->user($this->actors->current()),
        );
    }

    /** @return array<string, mixed> */
    public function serializeGenericAttributes(Model $record): array
    {
        return $this->serializer->serializeGenericAttributes($record);
    }

    public function communicationsRecordTitle(Model $record): ?string
    {
        return $this->serializer->communicationsRecordTitle($record);
    }

    /** @return list<string> */
    public function searchableColumns(string $resourceClass): array
    {
        return $this->introspection->searchableColumns($resourceClass);
    }

    public function definition(string $resourceClass): ResourceDefinition
    {
        $definition = $this->directory->definitionForClass($resourceClass);

        if (! $definition instanceof ResourceDefinition) {
            throw new RuntimeException(sprintf(
                'No admin resource definition is registered for [%s].',
                $resourceClass,
            ));
        }

        return $definition;
    }

    public function writer(string $resourceClass): ?AbstractAdminResourceWriter
    {
        $writer = $this->definitions->writer(ResourceSurface::Admin, $this->keyFor($resourceClass));

        return $writer instanceof AbstractAdminResourceWriter ? $writer : null;
    }
}
