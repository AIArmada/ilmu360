<?php

declare(strict_types=1);

namespace App\Support\Directory;

use App\Features\Directory\Api\DirectoryAdminContract;
use App\Features\Directory\Api\DirectoryAdminRecord;
use App\Features\Directory\Api\DirectoryAdminSchema;
use App\Features\Directory\Api\DirectoryRecordType;
use App\Platform\Models\MorphModelResolver;
use App\Support\Api\Admin\AdminResourceMutationService;
use App\Support\Api\Admin\AdminResourceRegistry;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Interim Directory admin adapter over the generic Filament admin composition.
 *
 * The Filament resources and generic admin mutation service move in later
 * slices; this adapter keeps their current schema/defaults/record behavior
 * behind the public Directory contract.
 *
 * @internal
 */
final readonly class EloquentDirectoryAdmin implements DirectoryAdminContract
{
    public function __construct(
        private AdminResourceRegistry $resources,
        private AdminResourceMutationService $mutations,
    ) {}

    public function resourceKey(DirectoryRecordType $type): string
    {
        return $this->resources->keyFor($this->resourceClass($type));
    }

    public function schema(
        DirectoryRecordType $type,
        string $operation = 'create',
        ?string $recordId = null,
    ): DirectoryAdminSchema {
        $resourceClass = $this->resourceClass($type);
        $record = $recordId !== null ? $this->recordModel($type, $recordId) : null;
        $updating = $operation === 'update';

        $schema = $this->mutations->schema(
            $resourceClass,
            $this->resources->keyFor($resourceClass),
            $updating ? 'update' : 'create',
            $record,
        );

        return new DirectoryAdminSchema(
            resourceKey: (string) ($schema['resource_key'] ?? $this->resources->keyFor($resourceClass)),
            operation: (string) ($schema['operation'] ?? ($updating ? 'update' : 'create')),
            fields: is_array($schema['fields'] ?? null) ? $schema['fields'] : [],
            rules: $this->mutations->rules($resourceClass, $updating),
            defaults: is_array($schema['defaults'] ?? null) ? $schema['defaults'] : [],
            catalogs: is_array($schema['catalogs'] ?? null) ? $schema['catalogs'] : [],
            conditionalRules: is_array($schema['conditional_rules'] ?? null) ? $schema['conditional_rules'] : [],
            currentMedia: is_array($schema['current_media'] ?? null) ? $schema['current_media'] : null,
        );
    }

    public function defaults(DirectoryRecordType $type, ?string $recordId = null): array
    {
        return $this->schema($type, $recordId !== null ? 'update' : 'create', $recordId)->defaults;
    }

    public function record(DirectoryRecordType $type, string $recordId): ?DirectoryAdminRecord
    {
        $record = $this->recordModel($type, $recordId);

        if (! $record instanceof Model) {
            return null;
        }

        $resourceClass = $this->resourceClass($type);
        $payload = $this->resources->serializeRecordDetail($resourceClass, $record);

        return new DirectoryAdminRecord(
            resourceKey: $this->resources->keyFor($resourceClass),
            id: (string) $record->getKey(),
            routeKey: (string) ($payload['route_key'] ?? $record->getRouteKey()),
            title: isset($payload['title']) ? (string) $payload['title'] : null,
            attributes: is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
            abilities: is_array($payload['abilities'] ?? null) ? $payload['abilities'] : [],
            panelRoutes: is_array($payload['panel_routes'] ?? null) ? $payload['panel_routes'] : [],
        );
    }

    /**
     * @return class-string<\Filament\Resources\Resource>
     */
    private function resourceClass(DirectoryRecordType $type): string
    {
        $resourceClass = $this->resources->resolveForModel(MorphModelResolver::resolve($type->morphAlias()));

        return $resourceClass ?? throw new RuntimeException("No admin resource is registered for [{$type->value}].");
    }

    private function recordModel(DirectoryRecordType $type, string $recordId): ?Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = MorphModelResolver::resolve($type->morphAlias());
        $model = new $modelClass;

        return $model
            ->resolveRouteBindingQuery($modelClass::query(), $recordId)
            ->first();
    }
}
