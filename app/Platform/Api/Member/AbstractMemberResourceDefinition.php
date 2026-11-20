<?php

declare(strict_types=1);

namespace App\Platform\Api\Member;

use AIArmada\Addressing\Models\Address;
use App\Features\Membership\Api\MemberPermissions;
use App\Features\Membership\Api\MembershipDirectory;
use App\Models\User;
use App\Platform\Api\ApiPagination;
use App\Platform\Api\Member\Contracts\MemberResourceHooks;
use App\Platform\Api\Resources\ResourceActor;
use App\Platform\Api\Resources\ResourceDefinition;
use App\Platform\Api\Resources\ResourceQuery;
use App\Platform\Api\Resources\ResourceReader;
use App\Platform\Api\Resources\ResourceReadSupport;
use App\Platform\Api\Resources\ResourceSurface;
use App\Platform\Api\Resources\ResourceWriter;
use App\Platform\Time\UserDateTimeFormatter;
use App\Shared\Auth\AccountActor;
use Carbon\CarbonInterface;
use Filament\Resources\Resource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Surface-specific member adapter over one owner Filament resource.
 *
 * Owner features extend this class and only supply their member Filament
 * resource and owner hook. Authorization, member scoping, metadata and
 * serialization are composed from the generic Platform helpers.
 */
abstract class AbstractMemberResourceDefinition implements ResourceDefinition, ResourceReader, ResourceWriter
{
    /** @param class-string<resource> $resourceClass */
    public function __construct(
        protected readonly MemberResourceContext $members,
        protected readonly MemberResourceHooks $hooks,
        protected readonly string $resourceClass,
    ) {}

    public function surface(): ResourceSurface
    {
        return ResourceSurface::Member;
    }

    public function key(): string
    {
        return $this->members->base->introspection->keyFor($this->resourceClass);
    }

    /** @return class-string<resource> */
    public function resourceClass(): string
    {
        return $this->resourceClass;
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return $this->resourceClass::getModel();
    }

    /** @return class-string<resource>|null */
    public function adminResourceClass(): ?string
    {
        return $this->hooks->adminResourceClass($this->resourceClass);
    }

    /**
     * @return array{
     *   key: string,
     *   resource_class: class-string<resource>,
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
    public function metadata(ResourceActor $actor): array
    {
        $user = $this->user($actor);

        return $this->members->metadata->build(
            $this->resourceClass,
            $this->key(),
            $this->hooks,
            $this->canAccessWith($user),
            $this->canWriteResourceWith($user),
        );
    }

    /** @return list<array<string, mixed>> */
    public function filters(ResourceActor $actor): array
    {
        return $this->hooks->filters($this->resourceClass);
    }

    public function canAccess(ResourceActor $actor): bool
    {
        return $this->canAccessWith($this->user($actor));
    }

    public function canWrite(ResourceActor $actor, ?string $recordId = null): bool
    {
        $user = $this->user($actor);

        if (! $this->canWriteResourceWith($user) || ! $user instanceof User) {
            return false;
        }

        if ($recordId === null) {
            return true;
        }

        try {
            $record = $this->resolveRecord($recordId);
        } catch (NotFoundHttpException) {
            return false;
        }

        return $this->canWriteRecordWith($user, $record);
    }

    public function canWriteExistingRecord(User $user, Model $record): bool
    {
        if ($this->adminResourceClass() === null) {
            return false;
        }

        try {
            $scopedRecord = $this->resolveRecord((string) $record->getRouteKey());
        } catch (NotFoundHttpException) {
            return false;
        }

        if ($scopedRecord->getKey() !== $record->getKey()) {
            return false;
        }

        return $this->canWriteRecordWith($user, $scopedRecord);
    }

    /** @return array<string, mixed> */
    public function list(ResourceActor $actor, ResourceQuery $request): array
    {
        abort_unless($this->canAccess($actor), 403);

        $user = $this->user($actor);
        $builder = $this->queryFor();
        $normalizedSearch = trim($request->search);

        if ($normalizedSearch !== '') {
            $this->applySearch($builder, $this->searchableColumns(), $normalizedSearch);
        }

        $this->applyDateFilters(
            $builder,
            $request->startsAfter,
            $request->startsBefore,
            $request->startsOnLocalDate,
        );

        ResourceReadSupport::applyDefaultOrdering($builder, $this->resourceClass);

        $records = $builder->paginate(
            perPage: ApiPagination::normalizePerPage($request->perPage, default: 15, max: 100),
            page: max($request->page, 1),
        );

        return [
            'data' => array_values(array_map(
                fn (Model $record): array => $this->serializeRecordFor($user, $record),
                $records->items(),
            )),
            'meta' => ResourceReadSupport::recordsMeta($this->metadata($actor), $records, $normalizedSearch),
        ];
    }

    /** @return array<string, mixed> */
    public function show(ResourceActor $actor, string $recordId): array
    {
        abort_unless($this->canAccess($actor), 403);

        $record = $this->resolveRecord($recordId);

        return [
            'data' => [
                'resource' => $this->metadata($actor),
                'record' => $this->serializeRecordDetailFor($this->user($actor), $record),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function related(ResourceActor $actor, string $recordId, string $relation, ResourceQuery $request): array
    {
        abort_unless($this->canAccess($actor), 403);

        $user = $this->user($actor);
        $record = $this->resolveRecord($recordId);
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
        $relatedDefinition = $this->members->directory->definitionForModel($relatedModel::class);
        $builder->select($relatedModel->qualifyColumn('*'));
        $normalizedSearch = trim($request->search);

        if ($normalizedSearch !== '') {
            if ($relatedDefinition instanceof AbstractMemberResourceDefinition) {
                $this->applySearch($builder, $relatedDefinition->searchableColumns(), $normalizedSearch);
            } else {
                $this->applyGenericSearch($builder, $relatedModel, $normalizedSearch);
            }
        }

        if ($relatedDefinition instanceof AbstractMemberResourceDefinition) {
            ResourceReadSupport::applyDefaultOrdering($builder, $relatedDefinition->resourceClass());
        } else {
            ResourceReadSupport::applyDefaultOrderingForModel($builder, $relatedModel);
        }

        $records = $builder->paginate(
            perPage: ApiPagination::normalizePerPage($request->perPage, default: 15, max: 100),
            page: max($request->page, 1),
        );

        return [
            'data' => array_values(array_map(
                fn (Model $relatedRecord): array => $relatedDefinition instanceof AbstractMemberResourceDefinition
                    ? $relatedDefinition->serializeRecordFor($user, $relatedRecord)
                    : $this->serializeGenericRecord($relatedRecord),
                $records->items(),
            )),
            'meta' => [
                'resource' => $resourceMeta,
                'parent_record' => $this->serializeRecordDetailFor($user, $record),
                'relation' => [
                    'name' => $relationName,
                    'method' => $relationMethod,
                    'related_model_class' => $relatedModel::class,
                    'related_resource' => $relatedDefinition instanceof AbstractMemberResourceDefinition
                        ? $relatedDefinition->metadata($actor)
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
        abort_unless($this->canAccess($actor), 403);

        $record = $this->resolveRecord($recordId);

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
            'data' => [
                'resource_key' => $this->key(),
                'record_key' => $recordId,
                'media' => $media,
                'media_count' => count($media),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function schema(ResourceActor $actor, string $operation, ?string $recordId): array
    {
        $user = $this->user($actor);
        abort_unless($user instanceof User, 403);
        abort_unless($operation === 'update', 404);
        abort_unless($this->adminResourceClass() !== null, 404);
        abort_unless($this->canWriteResourceWith($user), 403);

        if (! is_string($recordId) || trim($recordId) === '') {
            throw new NotFoundHttpException;
        }

        $record = $this->resolveRecord(trim($recordId));
        abort_unless($this->canWriteRecordWith($user, $record), 403);

        return $this->schemaForRecord($actor, $this->key(), $record);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validateAndPreview(ResourceActor $actor, string $operation, ?string $recordId, array $payload): array
    {
        $user = $this->user($actor);
        abort_unless($user instanceof User, 403);
        abort_unless($operation === 'update', 404);
        abort_unless($this->adminResourceClass() !== null, 404);
        abort_unless($this->canWriteResourceWith($user), 403);

        if (! is_string($recordId) || trim($recordId) === '') {
            throw new NotFoundHttpException;
        }

        $record = $this->resolveRecord($recordId);
        abort_unless($this->canWriteRecordWith($user, $record), 403);

        return $this->members->writer->previewForRecord($this, $user, $record, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function write(ResourceActor $actor, string $operation, ?string $recordId, array $payload): array
    {
        $user = $this->user($actor);
        abort_unless($user instanceof User, 403);
        abort_unless($operation === 'update', 404);
        abort_unless($this->adminResourceClass() !== null, 404);
        abort_unless($this->canWriteResourceWith($user), 403);

        if (! is_string($recordId) || trim($recordId) === '') {
            throw new NotFoundHttpException;
        }

        $record = $this->resolveRecord($recordId);
        abort_unless($this->canWriteRecordWith($user, $record), 403);

        $record = $this->members->writer->writeForRecord($this, $user, $record, $payload);

        return [
            'record_id' => (string) $record->getKey(),
            'record' => $this->serializeRecordDetailFor($user, $record),
        ];
    }

    /**
     * Legacy member mutation entrypoint for an already-resolved record.
     *
     * @return array<string, mixed>
     */
    public function schemaForRecord(ResourceActor $actor, string $resourceKey, Model $record): array
    {
        $user = $this->user($actor);
        abort_unless($user instanceof User, 403);
        abort_unless($this->adminResourceClass() !== null, 404);
        abort_unless($this->canWriteResourceWith($user), 403);
        abort_unless($this->canWriteRecordWith($user, $record), 403);

        return $this->members->writer->schemaForRecord($this, $resourceKey, $record);
    }

    /** @return array<string, mixed> */
    public function serializeRecordFor(?User $user, Model $record): array
    {
        $pages = $this->resourceClass::getPages();
        $resolvedUser = $user ?? $this->currentUser();

        $this->loadMissingApiRelations($record);

        return [
            'id' => (string) $record->getKey(),
            'route_key' => (string) $record->getRouteKey(),
            'title' => $this->recordTitle($record),
            'attributes' => $this->recordAttributes($record),
            'abilities' => $this->recordAbilitiesFor($resolvedUser, $record),
            'panel_routes' => [
                'view' => array_key_exists('view', $pages) ? $this->resourceClass::getUrl('view', ['record' => $record], panel: 'ahli') : null,
                'edit' => array_key_exists('edit', $pages) ? $this->resourceClass::getUrl('edit', ['record' => $record], panel: 'ahli') : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function serializeRecordDetailFor(?User $user, Model $record): array
    {
        $pages = $this->resourceClass::getPages();
        $resolvedUser = $user ?? $this->currentUser();

        $this->loadMissingApiRelations($record);

        return [
            'route_key' => (string) $record->getRouteKey(),
            'title' => $this->recordTitle($record),
            'attributes' => $this->recordAttributes($record),
            'abilities' => $this->recordAbilitiesFor($resolvedUser, $record),
            'panel_routes' => [
                'view' => array_key_exists('view', $pages) ? $this->resourceClass::getUrl('view', ['record' => $record], panel: 'ahli') : null,
                'edit' => array_key_exists('edit', $pages) ? $this->resourceClass::getUrl('edit', ['record' => $record], panel: 'ahli') : null,
            ],
        ];
    }

    /**
     * @return Builder<Model>
     */
    public function queryFor(): Builder
    {
        $query = $this->hooks->queryFor($this->resourceClass);

        if (! $query instanceof Builder) {
            /** @var Builder<Model> $query */
            $query = $this->resourceClass::getEloquentQuery();
        }

        $relations = $this->members->base->introspection->apiRelations($query->getModel());

        if ($relations !== []) {
            $query->with($relations);
        }

        return $query;
    }

    public function resolveRecord(string $recordKey): Model
    {
        $query = $this->queryFor();
        $model = $query->getModel();

        $record = $model
            ->resolveRouteBindingQuery($query, $recordKey)
            ->first();

        if ($record instanceof Model) {
            return $record;
        }

        abort(404);
    }

    /** @return list<string> */
    public function searchableColumns(): array
    {
        return $this->members->metadata->searchableColumns($this->resourceClass);
    }

    /**
     * @param  Builder<Model>  $builder
     * @param  array<int, string>  $columns
     */
    private function applySearch(Builder $builder, array $columns, string $search): void
    {
        if ($this->members->search->apply($builder, $search)) {
            return;
        }

        if ($columns === []) {
            return;
        }

        $model = $builder->getModel();

        $builder->where(function (Builder $query) use ($columns, $search, $model): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}($model->qualifyColumn($column), 'like', "%{$search}%");
            }
        });
    }

    /** @param Builder<Model> $builder */
    private function applyGenericSearch(Builder $builder, Model $model, string $search): void
    {
        ResourceReadSupport::applyGenericSearch($builder, $model, $search, [
            'title',
            'name',
            'middle_name',
            'family_name',
            'label',
            'slug',
            'email',
            'description',
            $model->getRouteKeyName(),
        ]);
    }

    /** @param Builder<Model> $builder */
    private function applyDateFilters(
        Builder $builder,
        ?string $startsAfter,
        ?string $startsBefore,
        ?string $startsOnLocalDate,
    ): void {
        if (! is_array($this->hooks->dateSemantics($this->resourceClass))) {
            return;
        }

        $model = $builder->getModel();
        $startsAtColumn = $model->qualifyColumn('starts_at');

        if (is_string($startsAfter) && trim($startsAfter) !== '') {
            $parsedStartsAfter = UserDateTimeFormatter::parseUserDateToUtc($startsAfter, false);

            if ($parsedStartsAfter instanceof CarbonInterface) {
                $builder->where($startsAtColumn, '>=', $parsedStartsAfter);
            }
        }

        if (is_string($startsBefore) && trim($startsBefore) !== '') {
            $parsedStartsBefore = UserDateTimeFormatter::parseUserDateToUtc($startsBefore, true);

            if ($parsedStartsBefore instanceof CarbonInterface) {
                $builder->where($startsAtColumn, '<=', $parsedStartsBefore);
            }
        }

        if (is_string($startsOnLocalDate) && trim($startsOnLocalDate) !== '') {
            $startsOnLocalDateStart = UserDateTimeFormatter::parseUserDateToUtc($startsOnLocalDate, false);
            $startsOnLocalDateEnd = UserDateTimeFormatter::parseUserDateToUtc($startsOnLocalDate, true);

            if ($startsOnLocalDateStart instanceof CarbonInterface && $startsOnLocalDateEnd instanceof CarbonInterface) {
                $builder->whereBetween($startsAtColumn, [$startsOnLocalDateStart, $startsOnLocalDateEnd]);
            }
        }
    }

    private function canAccessWith(?User $user): bool
    {
        if (! $user instanceof AccountActor) {
            return false;
        }

        $allowed = $this->hooks->canAccess(
            $this->resourceClass,
            $user,
            app(MembershipDirectory::class),
        );

        return $allowed === true;
    }

    private function canWriteResourceWith(?User $user): bool
    {
        if (! $user instanceof User || $this->adminResourceClass() === null) {
            return false;
        }

        $allowed = $this->hooks->canWriteResource(
            $this->resourceClass,
            $user,
            app(MemberPermissions::class),
        );

        return $allowed === true;
    }

    private function canWriteRecordWith(User $user, Model $record): bool
    {
        $allowed = $this->hooks->canWriteRecord($this->resourceClass, $user, $record);

        return $allowed ?? $user->can('update', $record);
    }

    /** @return array<string, bool> */
    public function recordAbilities(Model $record, ?User $user = null): array
    {
        return $this->recordAbilitiesFor($user ?? $this->currentUser(), $record);
    }

    /** @return array<string, bool> */
    private function recordAbilitiesFor(?User $user, Model $record): array
    {
        return [
            'view' => true,
            'edit' => $user instanceof User && $user->can('update', $record),
            'delete' => $user instanceof User && $user->can('delete', $record),
            'manage_members' => $user instanceof User && $user->can('manageMembers', $record),
        ];
    }

    private function recordTitle(Model $record): ?string
    {
        $title = $this->hooks->recordTitle($record);

        if ($title !== null) {
            return $title;
        }

        return $this->htmlableToString(
            $this->resourceClass::getRecordTitle($record),
        );
    }

    private function htmlableToString(Htmlable|string|null $value): ?string
    {
        if ($value instanceof Htmlable) {
            return (string) $value->toHtml();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function recordAttributes(Model $record): array
    {
        $attributes = $this->hooks->recordAttributes($record);

        return $attributes ?? $this->serializeAttributes($record);
    }

    /** @return array<string, mixed> */
    private function serializeAttributes(Model $record): array
    {
        $attributes = $record->toArray();

        if (method_exists($record, 'primaryAddress')) {
            $address = $record->primaryAddress();

            $attributes['address'] = $address instanceof Address
                ? $address->toArray()
                : [];
        }

        if (array_key_exists('contactMethods', $attributes)) {
            $attributes['contacts'] = $attributes['contactMethods'];
            unset($attributes['contactMethods']);
        }

        if (array_key_exists('socialProfiles', $attributes)) {
            $attributes['social_media'] = $attributes['socialProfiles'];
            unset($attributes['socialProfiles']);
        }

        unset($attributes['addresses']);

        foreach ($this->members->hooks->all() as $hook) {
            $attributes = $hook->augmentAttributes($record, $attributes);
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function serializeGenericAttributes(Model $record): array
    {
        $attributes = $record->toArray();

        if ($record instanceof User) {
            return Arr::except($attributes, [
                'email',
                'email_verified_at',
                'phone',
                'phone_verified_at',
                'daily_prayer_institutions',
                'friday_prayer_institutions',
            ]);
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function serializeGenericRecord(Model $record): array
    {
        return ResourceReadSupport::serializeRelatedRecord(
            $record,
            $this->serializeGenericAttributes($record),
        );
    }

    private function loadMissingApiRelations(Model $record): void
    {
        $relations = $this->members->base->introspection->apiRelations($record);

        if ($relations !== []) {
            $record->loadMissing($relations);
        }
    }

    private function user(ResourceActor $actor): ?User
    {
        return $this->members->base->actors->user($actor);
    }

    private function currentUser(): ?User
    {
        return $this->members->base->actors->user(
            $this->members->base->actors->current(ResourceSurface::Member),
        );
    }
}
