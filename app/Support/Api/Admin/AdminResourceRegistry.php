<?php

namespace App\Support\Api\Admin;

use AIArmada\Signals\Models\TrackedProperty;
use App\Data\Api\Event\EventPayloadData;
use App\Enums\EventFormat;
use App\Enums\EventStructure;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use UnitEnum;

/**
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

    /** @var array<class-string<resource>, array<string, mixed>> */
    private array $metadataCache = [];

    /** @var array<class-string<resource>, list<string>> */
    private array $searchableColumnsCache = [];

    /** @var array<class-string<resource>, list<array{key: string, label: string, type: string, options?: array<string, string>}>> */
    private array $filtersCache = [];

    public function __construct(
        private readonly AdminResourceMutationService $mutationService,
    ) {}

    /**
     * @return list<class-string<resource>>
     */
    public function resources(): array
    {
        if (is_array($this->resourcesCache)) {
            return $this->resourcesCache;
        }

        /** @var array<int|string, class-string<resource>> $resources */
        $resources = Filament::getPanel('admin')->getResources();

        return $this->resourcesCache = array_values($resources);
    }

    /**
     * @return list<class-string<resource>>
     */
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

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function resolveForModel(string $modelClass): ?string
    {
        foreach ($this->resources() as $resourceClass) {
            if ($resourceClass::getModel() === $modelClass) {
                return $resourceClass;
            }
        }

        return null;
    }

    public function canAccessResource(string $resourceClass): bool
    {
        return $this->canViewAny($resourceClass)
            || $this->canCreate($resourceClass)
            || $resourceClass::canDeleteAny()
            || $resourceClass::canForceDeleteAny()
            || $resourceClass::canRestoreAny()
            || $resourceClass::canReorder();
    }

    /**
     * @return AdminResourceMeta
     */
    public function metadata(string $resourceClass): array
    {
        if (array_key_exists($resourceClass, $this->metadataCache)) {
            return $this->metadataCache[$resourceClass];
        }

        $key = $this->keyFor($resourceClass);
        $pages = $resourceClass::getPages();
        $supportsMutation = $this->mutationService->supports($resourceClass);
        $filters = $this->filterMetadata($resourceClass);
        $dateSemantics = is_a($resourceClass::getModel(), Event::class, true)
            ? [
                'storage_timezone' => 'UTC',
                'viewer_timezone' => 'resolved at request time',
                'local_fields' => ['starts_at_local', 'starts_on_local_date', 'ends_at_local'],
                'local_date_filter' => 'starts_on_local_date',
            ]
            : null;

        return $this->metadataCache[$resourceClass] = [
            'key' => $key,
            'resource_class' => $resourceClass,
            'model_class' => $resourceClass::getModel(),
            'model_label' => $resourceClass::getModelLabel(),
            'plural_model_label' => $resourceClass::getPluralModelLabel(),
            'navigation_group' => $this->stringOrNull($resourceClass::getNavigationGroup()),
            'record_title_attribute' => $resourceClass::getRecordTitleAttribute(),
            'pages' => [
                'index' => array_key_exists('index', $pages),
                'create' => array_key_exists('create', $pages),
                'view' => array_key_exists('view', $pages),
                'edit' => array_key_exists('edit', $pages),
            ],
            'relations' => $this->relationNames($resourceClass),
            'abilities' => [
                'view_any' => $this->canViewAny($resourceClass),
                'create' => $this->canCreate($resourceClass),
                'delete_any' => $resourceClass::canDeleteAny(),
                'force_delete_any' => $resourceClass::canForceDeleteAny(),
                'restore_any' => $resourceClass::canRestoreAny(),
                'reorder' => $resourceClass::canReorder(),
            ],
            'filters' => $filters,
            'write_support' => [
                'schema' => $supportsMutation,
                'store' => $supportsMutation && array_key_exists('create', $pages),
                'update' => $supportsMutation && array_key_exists('edit', $pages),
            ],
            'api_routes' => [
                'collection' => route('api.admin.resources.index', ['resourceKey' => $key], false),
                'meta' => route('api.admin.resources.meta', ['resourceKey' => $key], false),
                'schema' => $supportsMutation
                    ? route('api.admin.resources.schema', ['resourceKey' => $key], false)
                    : null,
                'store' => $supportsMutation && array_key_exists('create', $pages)
                    ? route('api.admin.resources.store', ['resourceKey' => $key], false)
                    : null,
                'related_collection' => route('api.admin.resources.related', ['resourceKey' => $key, 'recordKey' => 'record', 'relation' => 'relation'], false),
                'item_template' => route('api.admin.resources.show', ['resourceKey' => $key, 'recordKey' => 'record'], false),
                'update_template' => $supportsMutation && array_key_exists('edit', $pages)
                    ? route('api.admin.resources.update', ['resourceKey' => $key, 'recordKey' => 'record'], false)
                    : null,
            ],
            'mcp_tools' => $this->mcpTools($key, $supportsMutation, $pages, $dateSemantics, $filters),
            'panel_routes' => [
                'index' => array_key_exists('index', $pages) ? $resourceClass::getUrl('index', panel: 'admin') : null,
                'create' => array_key_exists('create', $pages) ? $resourceClass::getUrl('create', panel: 'admin') : null,
                'view_template' => array_key_exists('view', $pages) ? $resourceClass::getUrl('view', ['record' => 'record'], panel: 'admin') : null,
                'edit_template' => array_key_exists('edit', $pages) ? $resourceClass::getUrl('edit', ['record' => 'record'], panel: 'admin') : null,
            ],
            'timezone_sensitive' => $dateSemantics !== null,
            'date_semantics' => $dateSemantics,
        ];
    }

    /**
     * @return Builder<Model>
     */
    public function queryFor(string $resourceClass): Builder
    {
        /** @var Builder<Model> $query */
        $query = $resourceClass::getEloquentQuery();

        $this->applyDefaultApiEagerLoads($query);

        return $query;
    }

    public function resolveRecord(string $resourceClass, string $recordKey): Model
    {
        $model = $this->queryFor($resourceClass)->getModel();

        $routeBoundRecord = $model
            ->resolveRouteBindingQuery($this->queryFor($resourceClass), $recordKey)
            ->first();

        if ($routeBoundRecord instanceof Model) {
            return $routeBoundRecord;
        }

        abort(404);
    }

    /**
     * @return array<string, bool>
     */
    public function recordAbilities(string $resourceClass, Model $record): array
    {
        return [
            'view' => $this->canActOnRecord('view', $resourceClass, $record),
            'edit' => $this->canActOnRecord('update', $resourceClass, $record),
            'delete' => $this->canActOnRecord('delete', $resourceClass, $record),
            'force_delete' => $resourceClass::canForceDelete($record),
            'restore' => $resourceClass::canRestore($record),
            'replicate' => $resourceClass::canReplicate($record),
        ];
    }

    public function keyFor(string $resourceClass): string
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::getModel();

        return Str::kebab(Str::pluralStudly(class_basename($modelClass)));
    }

    /**
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    public function filters(string $resourceClass): array
    {
        if (array_key_exists($resourceClass, $this->filtersCache)) {
            return $this->filtersCache[$resourceClass];
        }

        return $this->filtersCache[$resourceClass] = match ($resourceClass) {
            EventResource::class => [
                [
                    'key' => 'status',
                    'label' => 'Status',
                    'type' => 'select',
                    'options' => [
                        'draft' => 'Draft',
                        'pending' => 'Pending Review',
                        'needs_changes' => 'Needs Changes',
                        'approved' => 'Approved',
                        'cancelled' => 'Cancelled',
                        'rejected' => 'Rejected',
                    ],
                ],
                [
                    'key' => 'visibility',
                    'label' => 'Visibility',
                    'type' => 'select',
                    'options' => collect(EventVisibility::cases())->mapWithKeys(
                        static fn (EventVisibility $visibility): array => [$visibility->value => $visibility->getLabel()]
                    )->all(),
                ],
                [
                    'key' => 'event_structure',
                    'label' => 'Event Structure',
                    'type' => 'select',
                    'options' => collect(EventStructure::cases())->mapWithKeys(
                        static fn (EventStructure $structure): array => [$structure->value => $structure->label()]
                    )->all(),
                ],
                [
                    'key' => 'event_format',
                    'label' => 'Event Format',
                    'type' => 'select',
                    'options' => collect(EventFormat::cases())->mapWithKeys(
                        static fn (EventFormat $format): array => [$format->value => $format->getLabel()]
                    )->all(),
                ],
                [
                    'key' => 'event_type',
                    'label' => 'Event Type',
                    'type' => 'select',
                    'options' => collect(EventType::cases())->mapWithKeys(
                        static fn (EventType $eventType): array => [$eventType->value => $eventType->getLabel()]
                    )->all(),
                ],
                [
                    'key' => 'timing_mode',
                    'label' => 'Timing Mode',
                    'type' => 'select',
                    'options' => collect(TimingMode::cases())->mapWithKeys(
                        static fn (TimingMode $timingMode): array => [$timingMode->value => $timingMode->label()]
                    )->all(),
                ],
                [
                    'key' => 'prayer_reference',
                    'label' => 'Prayer Reference',
                    'type' => 'select',
                    'options' => collect(PrayerReference::cases())->mapWithKeys(
                        static fn (PrayerReference $reference): array => [$reference->value => $reference->label()]
                    )->all(),
                ],
                [
                    'key' => 'is_active',
                    'label' => 'Active',
                    'type' => 'boolean',
                ],
            ],
            SpeakerResource::class => [
                [
                    'key' => 'status',
                    'label' => 'Status',
                    'type' => 'select',
                    'options' => [
                        'pending' => 'Pending',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                    ],
                ],
                [
                    'key' => 'is_active',
                    'label' => 'Active',
                    'type' => 'boolean',
                ],
                [
                    'key' => 'has_events',
                    'label' => 'Has Events',
                    'type' => 'boolean',
                ],
            ],
            default => [],
        };
    }

    /**
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    public function filterMetadata(string $resourceClass): array
    {
        return array_map(
            static fn (array $filter): array => Arr::only($filter, ['key', 'label', 'type', 'options']),
            $this->filters($resourceClass),
        );
    }

    /**
     * @param  array<string, mixed>  $pages
     * @param  array<string, mixed>|null  $dateSemantics
     * @param  list<array{key: string, label: string, type: string, options?: array<string, string>}>  $filters
     * @return array<string, mixed>
     */
    private function mcpTools(string $key, bool $supportsMutation, array $pages, ?array $dateSemantics, array $filters): array
    {
        $listRecordsArguments = [
            'resource_key' => $key,
            'search' => null,
            'page' => 1,
            'per_page' => 15,
        ];

        if ($filters !== []) {
            $listRecordsArguments['filters'] = 'object';
        }

        if ($dateSemantics !== null) {
            $listRecordsArguments['starts_after'] = null;
            $listRecordsArguments['starts_before'] = null;
            $listRecordsArguments['starts_on_local_date'] = null;
        }

        return [
            'list_resources' => [
                'tool' => 'admin-list-resources',
                'arguments' => [
                    'verbose' => true,
                    'writable_only' => false,
                ],
            ],
            'get_meta' => [
                'tool' => 'admin-get-resource-meta',
                'arguments' => [
                    'resource_key' => $key,
                ],
            ],
            'list_records' => [
                'tool' => 'admin-list-records',
                'arguments' => $listRecordsArguments,
            ],
            'list_related_records' => [
                'tool' => 'admin-list-related-records',
                'arguments' => [
                    'resource_key' => $key,
                    'record_key' => 'record',
                    'relation' => 'relation',
                    'search' => null,
                    'page' => 1,
                    'per_page' => 15,
                ],
            ],
            'get_record' => [
                'tool' => 'admin-get-record',
                'arguments' => [
                    'resource_key' => $key,
                    'record_key' => 'record',
                ],
            ],
            'get_record_actions' => [
                'tool' => 'admin-get-record-actions',
                'arguments' => [
                    'resource_key' => $key,
                    'record_key' => 'record',
                ],
            ],
            'generate_event_cover_image' => $key === 'events'
                ? [
                    'tool' => 'admin-upload-event-cover-image',
                    'arguments' => [
                        'event_key' => 'record',
                    ],
                    'prerequisite_prompt' => 'admin-event-cover-image-prompt',
                ]
                : null,
            'generate_event_poster_image' => $key === 'events'
                ? [
                    'tool' => 'admin-upload-event-poster-image',
                    'arguments' => [
                        'event_key' => 'record',
                    ],
                    'prerequisite_prompt' => 'admin-event-poster-image-prompt',
                ]
                : null,
            'get_create_schema' => $supportsMutation
                ? [
                    'tool' => 'admin-get-write-schema',
                    'arguments' => [
                        'resource_key' => $key,
                        'operation' => 'create',
                    ],
                ]
                : null,
            'get_update_schema' => $supportsMutation
                ? [
                    'tool' => 'admin-get-write-schema',
                    'arguments' => [
                        'resource_key' => $key,
                        'operation' => 'update',
                        'record_key' => 'record',
                    ],
                ]
                : null,
            'create' => $supportsMutation && array_key_exists('create', $pages)
                ? [
                    'tool' => 'admin-create-record',
                    'arguments' => [
                        'resource_key' => $key,
                        'payload' => 'object',
                        'validate_only' => false,
                        'apply_defaults' => false,
                    ],
                ]
                : null,
            'update' => $supportsMutation && array_key_exists('edit', $pages)
                ? [
                    'tool' => 'admin-update-record',
                    'arguments' => [
                        'resource_key' => $key,
                        'record_key' => 'record',
                        'payload' => 'object',
                        'validate_only' => false,
                        'apply_defaults' => false,
                    ],
                ]
                : null,
        ];
    }

    /**
     * @return array{
     *   id: string,
     *   route_key: string,
     *   title: string|null,
     *   attributes: array<string, mixed>,
     *   abilities: array<string, bool>,
     *   panel_routes: array<string, string|null>
     * }
     */
    public function serializeRecord(string $resourceClass, Model $record): array
    {
        $pages = $resourceClass::getPages();

        $this->loadMissingApiRelations($record);

        return [
            'id' => (string) $record->getKey(),
            'route_key' => (string) $record->getRouteKey(),
            'title' => $this->htmlableToString($resourceClass::getRecordTitle($record)),
            'attributes' => $record instanceof Event
                ? EventPayloadData::fromModel($record)->toArray()
                : $this->serializeAttributes($record),
            'abilities' => $this->recordAbilities($resourceClass, $record),
            'panel_routes' => [
                'view' => array_key_exists('view', $pages) ? $resourceClass::getUrl('view', ['record' => $record], panel: 'admin') : null,
                'edit' => array_key_exists('edit', $pages) ? $resourceClass::getUrl('edit', ['record' => $record], panel: 'admin') : null,
            ],
        ];
    }

    /**
     * @return array{
     *   route_key: string,
     *   title: string|null,
     *   attributes: array<string, mixed>,
     *   abilities: array<string, bool>,
     *   panel_routes: array<string, string|null>
     * }
     */
    public function serializeRecordDetail(string $resourceClass, Model $record): array
    {
        $pages = $resourceClass::getPages();

        $this->loadMissingApiRelations($record);

        $attributes = $record instanceof Event
            ? EventPayloadData::fromModel($record)->toArray()
            : $this->serializeAttributes($record);

        return [
            'route_key' => (string) $record->getRouteKey(),
            'title' => $this->htmlableToString($resourceClass::getRecordTitle($record)),
            'attributes' => $this->stripResponsiveImages($attributes),
            'abilities' => $this->recordAbilities($resourceClass, $record),
            'panel_routes' => [
                'view' => array_key_exists('view', $pages) ? $resourceClass::getUrl('view', ['record' => $record], panel: 'admin') : null,
                'edit' => array_key_exists('edit', $pages) ? $resourceClass::getUrl('edit', ['record' => $record], panel: 'admin') : null,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function searchableColumns(string $resourceClass): array
    {
        if (array_key_exists($resourceClass, $this->searchableColumnsCache)) {
            return $this->searchableColumnsCache[$resourceClass];
        }

        $modelClass = $resourceClass::getModel();
        $model = new $modelClass;
        $table = $model->getTable();
        $candidates = [
            $resourceClass::getRecordTitleAttribute(),
            $model->getRouteKeyName(),
            'name',
            'title',
            'slug',
            'email',
        ];

        $normalizedCandidates = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $normalizedCandidates[] = $candidate;
        }

        $normalizedCandidates = array_values(array_unique($normalizedCandidates));

        return $this->searchableColumnsCache[$resourceClass] = array_values(array_filter(
            $normalizedCandidates,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));
    }

    /**
     * @return list<string>
     */
    private function relationNames(string $resourceClass): array
    {
        $relationNames = [];

        foreach ($resourceClass::getRelations() as $relation) {
            $name = $this->relationName($relation);

            if (! filled($name)) {
                continue;
            }

            $relationNames[] = $name;
        }

        return array_values(array_unique($relationNames));
    }

    private function relationName(mixed $relation): ?string
    {
        $relationClass = is_object($relation) ? $relation::class : (is_string($relation) ? $relation : null);

        if (! is_string($relationClass) || $relationClass === '') {
            return null;
        }

        $reflection = new \ReflectionClass($relationClass);

        if ($reflection->hasProperty('relationship')) {
            $property = $reflection->getProperty('relationship');

            if ($property->isStatic()) {
                $value = $property->getValue();

                if (is_string($value) && $value !== '') {
                    return Str::snake($value);
                }
            }
        }

        return Str::snake(class_basename($relationClass));
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function canCreate(string $resourceClass): bool
    {
        $user = auth()->user();
        $modelClass = $resourceClass::getModel();

        return $user instanceof User
            ? $user->can('create', $modelClass)
            : $resourceClass::canCreate();
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function canViewAny(string $resourceClass): bool
    {
        $user = auth()->user();
        $modelClass = $resourceClass::getModel();

        return $user instanceof User
            ? $user->can('viewAny', $modelClass)
            : $resourceClass::canViewAny();
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyDefaultApiEagerLoads(Builder $query): void
    {
        $relations = $this->apiRelations($query->getModel());

        if ($relations !== []) {
            $query->with($relations);
        }
    }

    private function loadMissingApiRelations(Model $record): void
    {
        $relations = $this->apiRelations($record);

        if ($relations !== []) {
            $record->loadMissing($relations);
        }
    }

    /**
     * Recursively strip the `responsive_images` key from any nested array in the serialized payload.
     * This prevents base64 SVG placeholders and large responsive-image URL lists from bloating MCP responses.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripResponsiveImages(array $data): array
    {
        unset($data['responsive_images']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->stripResponsiveImages($value);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttributes(Model $record): array
    {
        $attributes = $record->toArray();

        if ($record instanceof TrackedProperty) {
            $attributes['write_key'] = filled($record->write_key) ? 'present' : null;
        }

        if ($record instanceof User) {
            $attributes = Arr::except($attributes, [
                'email',
                'email_verified_at',
                'phone',
                'phone_verified_at',
                'daily_prayer_institution_id',
                'friday_prayer_institution_id',
            ]);
        }

        if ($record instanceof Speaker && is_array($attributes['address'] ?? null)) {
            $attributes['address'] = Arr::only($attributes['address'], [
                'country_id',
                'state_id',
                'district_id',
                'subdistrict_id',
            ]);
        }

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private function apiRelations(Model $model): array
    {
        $relations = [];

        if (method_exists($model, 'address')) {
            $relations[] = 'address';
        }

        if (method_exists($model, 'contacts')) {
            $relations[] = 'contacts';
        }

        if (method_exists($model, 'socialMedia')) {
            $relations[] = 'socialMedia';
        }

        return $relations;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function canActOnRecord(string $ability, string $resourceClass, Model $record): bool
    {
        $user = auth()->user();

        return $user instanceof User
            ? $user->can($ability, $record)
            : match ($ability) {
                'view' => $resourceClass::canView($record),
                'update' => $resourceClass::canEdit($record),
                'delete' => $resourceClass::canDelete($record),
                default => false,
            };
    }

    private function stringOrNull(string|UnitEnum|null $value): ?string
    {
        if ($value instanceof UnitEnum) {
            return $value instanceof \BackedEnum ? (string) $value->value : $value->name;
        }

        return filled($value) ? $value : null;
    }

    private function htmlableToString(Htmlable|string|null $value): ?string
    {
        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }

        return $value;
    }
}
