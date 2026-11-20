<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use App\Models\User;
use App\Platform\Api\Resources\ResourceActor;
use App\Platform\Api\Resources\ResourceActorResolver;
use App\Platform\Api\Resources\ResourceIntrospection;
use App\Platform\Api\Resources\ResourceSurface;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thin member facade over the shared resource definition registry.
 *
 * @phpstan-type MemberResourceMeta array{
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
 *   write_support: array<string, bool>,
 *   mcp_tools: array<string, mixed>,
 *   panel_routes: array<string, string|null>,
 *   timezone_sensitive: bool,
 *   date_semantics: array<string, mixed>|null
 * }
 */
class MemberResourceRegistry
{
    public function __construct(
        private readonly MemberResourceDirectory $directory,
        private readonly ResourceActorResolver $actors,
        private readonly MemberResourceMetadataBuilder $metadataBuilder,
        private readonly ResourceIntrospection $introspection,
    ) {}

    /**
     * @return list<class-string<resource>>
     */
    public function resources(): array
    {
        return $this->directory->resourceClasses();
    }

    /**
     * @return list<class-string<resource>>
     */
    public function accessibleResources(): array
    {
        return array_values(array_filter(
            $this->resources(),
            $this->canAccessResource(...),
        ));
    }

    public function resolve(string $resourceKey): ?string
    {
        return $this->directory->resolve($resourceKey)?->resourceClass();
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function resolveForModel(string $modelClass): ?string
    {
        return $this->directory->definitionForModel($modelClass)?->resourceClass();
    }

    public function canAccessResource(string $resourceClass): bool
    {
        return $this->definitionFor($resourceClass)?->canAccess($this->actor()) ?? false;
    }

    /**
     * @return MemberResourceMeta
     */
    public function metadata(string $resourceClass): array
    {
        return $this->requireDefinition($resourceClass)->metadata($this->actor());
    }

    /**
     * @return Builder<Model>
     */
    public function queryFor(string $resourceClass): Builder
    {
        return $this->requireDefinition($resourceClass)->queryFor();
    }

    public function resolveRecord(string $resourceClass, string $recordKey): Model
    {
        return $this->requireDefinition($resourceClass)->resolveRecord($recordKey);
    }

    /**
     * @return array<string, bool>
     */
    public function recordAbilities(Model $record): array
    {
        $definition = $this->directory->definitionForModel($record::class);

        if (! $definition instanceof AbstractMemberResourceDefinition) {
            throw new RuntimeException(sprintf(
                'No member resource definition is registered for [%s].',
                $record::class,
            ));
        }

        return $definition->recordAbilities($record, $this->user());
    }

    public function canWriteResource(string $resourceClass): bool
    {
        return $this->definitionFor($resourceClass)?->canWrite($this->actor()) ?? false;
    }

    public function keyFor(string $resourceClass): string
    {
        return $this->definitionFor($resourceClass)?->key()
            ?? $this->introspection->keyFor($resourceClass);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRecord(string $resourceClass, Model $record): array
    {
        return $this->requireDefinition($resourceClass)->serializeRecordFor($this->user(), $record);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRecordDetail(string $resourceClass, Model $record): array
    {
        return $this->requireDefinition($resourceClass)->serializeRecordDetailFor($this->user(), $record);
    }

    /**
     * @return list<string>
     */
    public function searchableColumns(string $resourceClass): array
    {
        return $this->metadataBuilder->searchableColumns($resourceClass);
    }

    public function definitionFor(string $resourceClass): ?AbstractMemberResourceDefinition
    {
        return $this->directory->definition($resourceClass);
    }

    public function definitionForKey(string $resourceKey): ?AbstractMemberResourceDefinition
    {
        return $this->directory->resolve($resourceKey);
    }

    private function requireDefinition(string $resourceClass): AbstractMemberResourceDefinition
    {
        return $this->definitionFor($resourceClass) ?? throw new RuntimeException(sprintf(
            'No member resource definition is registered for [%s].',
            $resourceClass,
        ));
    }

    private function actor(): ResourceActor
    {
        return $this->actors->current(ResourceSurface::Member);
    }

    private function user(): ?User
    {
        return $this->actors->user($this->actor());
    }
}
