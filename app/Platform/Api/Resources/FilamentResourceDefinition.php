<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use App\Models\User;
use App\Platform\Api\ApiPagination;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Generic admin resource definition for a Filament resource class.
 *
 * Owner features extend this class (or register it directly for package-owned
 * resources) and only add behaviour that is genuinely theirs.
 */
class FilamentResourceDefinition implements FilamentBackedResourceDefinition
{
    /** @param class-string<resource> $resourceClass */
    public function __construct(
        protected readonly ResourceDefinitionContext $context,
        protected readonly string $resourceClass,
        protected readonly ?AbstractAdminResourceWriter $writer = null,
    ) {}

    public function surface(): ResourceSurface
    {
        return ResourceSurface::Admin;
    }

    public function key(): string
    {
        return $this->context->introspection->keyFor($this->resourceClass);
    }

    /** @return class-string<resource> */
    public function resourceClass(): string
    {
        return $this->resourceClass;
    }

    public function writer(): ?AbstractAdminResourceWriter
    {
        return $this->writer;
    }

    /** @return array<string, mixed> */
    public function metadata(ResourceActor $actor): array
    {
        return $this->context->metadata->build(
            $this->resourceClass,
            $this->writer instanceof AbstractAdminResourceWriter,
            $this->context->actors->user($actor),
        );
    }

    /** @return list<array<string, mixed>> */
    public function filters(ResourceActor $actor): array
    {
        return $this->context->metadata->filtersFor($this->resourceClass);
    }

    public function canAccess(ResourceActor $actor): bool
    {
        return $this->context->introspection->canAccessResource(
            $this->resourceClass,
            $this->context->actors->user($actor),
        );
    }

    public function canWrite(ResourceActor $actor, ?string $recordId = null): bool
    {
        if (! $this->writer instanceof AbstractAdminResourceWriter) {
            return false;
        }

        $user = $this->context->actors->user($actor);

        if (! $user instanceof User) {
            return false;
        }

        if ($recordId === null) {
            return $user->can('create', $this->resourceClass::getModel());
        }

        try {
            $record = $this->context->queries->resolveRecord($this->resourceClass, $recordId);
        } catch (NotFoundHttpException) {
            return false;
        }

        return $user->can('update', $record);
    }

    /** @return array<string, mixed> */
    public function list(ResourceActor $actor, ResourceQuery $query): array
    {
        $user = $this->context->actors->user($actor);
        $modelClass = $this->resourceClass::getModel();

        abort_unless(
            $user instanceof User
                ? $user->can('viewAny', $modelClass)
                : $this->resourceClass::canViewAny(),
            403,
        );

        $builder = $this->context->queries->forResource($this->resourceClass);
        $normalizedSearch = trim($query->search);

        if ($normalizedSearch !== '') {
            $this->context->queries->applySearch($builder, $this->resourceClass, $normalizedSearch);
        }

        $this->context->queries->applyFilters($builder, $this->resourceClass, $query->filters);

        $this->context->queries->applyDateFilters(
            $builder,
            $this->context->metadata->dateSemanticsFor($this->resourceClass),
            $query->startsAfter,
            $query->startsBefore,
            $query->startsOnLocalDate,
        );

        $this->context->queries->applyDefaultOrdering($builder, $this->resourceClass);

        $records = $builder->paginate(
            perPage: ApiPagination::normalizePerPage($query->perPage, default: 15, max: 100),
            page: max($query->page, 1),
        );

        return [
            'data' => array_values(array_map(
                fn (Model $record): array => $this->context->serializer->serializeRecord($this->resourceClass, $record, $user),
                $records->items(),
            )),
            'meta' => ResourceReadSupport::recordsMeta($this->metadata($actor), $records, $normalizedSearch),
        ];
    }

    /** @return array<string, mixed> */
    public function show(ResourceActor $actor, string $recordId): array
    {
        $user = $this->context->actors->user($actor);
        $record = $this->context->queries->resolveRecord($this->resourceClass, $recordId);
        $abilities = $this->context->serializer->recordAbilities($this->resourceClass, $record, $user);

        abort_unless(collect($abilities)->contains(true), 403);

        return [
            'data' => [
                'record' => $this->context->serializer->serializeRecordDetail($this->resourceClass, $record, $user),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function related(ResourceActor $actor, string $recordId, string $relation, ResourceQuery $query): array
    {
        $user = $this->context->actors->user($actor);
        $record = $this->context->queries->resolveRecord($this->resourceClass, $recordId);
        $abilities = $this->context->serializer->recordAbilities($this->resourceClass, $record, $user);

        abort_unless(collect($abilities)->contains(true), 403);

        $resourceMeta = $this->metadata($actor);
        $relationName = Str::snake(trim($relation));

        abort_unless(in_array($relationName, $resourceMeta['relations'], true), 404);

        $relationMethod = Str::camel($relationName);

        if (! method_exists($record, $relationMethod)) {
            throw new NotFoundHttpException;
        }

        /** @var Relation<Model, Model, mixed> $relationQuery */
        $relationQuery = $record->{$relationMethod}();
        $builder = $relationQuery->getQuery();
        $relatedModel = $relationQuery->getRelated();
        $relatedResourceClass = $this->context->directory->resourceClassForModel($relatedModel::class);
        $builder->select($relatedModel->qualifyColumn('*'));
        $normalizedSearch = trim($query->search);

        if ($normalizedSearch !== '') {
            if ($relatedResourceClass !== null) {
                $this->context->queries->applySearch($builder, $relatedResourceClass, $normalizedSearch);
            } else {
                $this->context->queries->applyGenericSearch($builder, $relatedModel, $normalizedSearch);
            }
        }

        if ($relatedResourceClass !== null) {
            $this->context->queries->applyDefaultOrdering($builder, $relatedResourceClass);
        } else {
            $this->context->queries->applyDefaultOrderingForModel($builder, $relatedModel);
        }

        $records = $builder->paginate(
            perPage: ApiPagination::normalizePerPage($query->perPage, default: 15, max: 100),
            page: max($query->page, 1),
        );

        return [
            'data' => array_values(array_map(
                fn (Model $relatedRecord): array => $this->context->serializer->serializeRelatedRecord($relatedResourceClass, $relatedRecord, $user),
                $records->items(),
            )),
            'meta' => [
                'resource' => $resourceMeta,
                'parent_record' => $this->context->serializer->serializeRecordDetail($this->resourceClass, $record, $user),
                'relation' => [
                    'name' => $relationName,
                    'method' => $relationMethod,
                    'related_model_class' => $relatedModel::class,
                    'related_resource' => $relatedResourceClass !== null
                        ? $this->relatedMetadata($relatedResourceClass, $actor)
                        : null,
                ],
                'search' => $normalizedSearch !== '' ? $normalizedSearch : null,
                'pagination' => ApiPagination::paginationMeta($records),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function media(ResourceActor $actor, string $recordId): array
    {
        $user = $this->context->actors->user($actor);
        $record = $this->context->queries->resolveRecord($this->resourceClass, $recordId);
        $abilities = $this->context->serializer->recordAbilities($this->resourceClass, $record, $user);

        abort_unless(collect($abilities)->contains(true), 403);

        return [
            'data' => $this->recordMediaData($this->key(), $recordId, $record),
        ];
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, mixed>
     */
    protected function relatedMetadata(string $resourceClass, ResourceActor $actor): array
    {
        $definition = $this->context->directory->definition($resourceClass);

        return $this->context->metadata->build(
            $resourceClass,
            $definition instanceof FilamentBackedResourceDefinition && $definition->writer() instanceof AbstractAdminResourceWriter,
            $this->context->actors->user($actor),
        );
    }

    /** @return array<string, mixed> */
    protected function recordMediaData(string $resourceKey, string $recordKey, Model $record): array
    {
        $media = $record instanceof HasMedia
            ? $record->getMedia()
                ->map(fn (Media $item): array => [
                    'id' => $item->id,
                    'collection_name' => $item->collection_name,
                    'file_name' => $item->file_name,
                    'mime_type' => $item->mime_type,
                    'size' => $item->size,
                    'url' => $item->getUrl(),
                ])
                ->values()
                ->all()
            : [];

        return [
            'resource_key' => $resourceKey,
            'record_key' => $recordKey,
            'media' => $media,
            'media_count' => count($media),
        ];
    }
}
