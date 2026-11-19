<?php

namespace App\Platform\Api\Admin;

use AIArmada\Addressing\Actions\SaveAddressAreaAction;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\FilamentAddressing\Resources\AddressAreaResource;
use App\Features\Events\Api\AdminEventWriter;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\RegistrationScope;
use App\Features\Events\Api\SeriesAuthoring;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\EntryPoints\Filament\Resources\Series\SeriesResource;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Inspirations\Api\InspirationAdminContract;
use App\Features\Reports\Api\ReportAdminContract;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Shared\Contributions\ContributionEntityState;
use App\Shared\Events\Enums\EventAgeGroup;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\EventGenderRestriction;
use App\Shared\Events\Enums\EventKeyPersonRole;
use App\Shared\Events\Enums\EventVisibility;
use App\Shared\Events\EventCategoryCatalog;
use App\Shared\Prayer\EventPrayerTime;
use App\Shared\Taxonomy\EventTaxonomyCode;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\HasMedia;

class AdminResourceMutationService
{
    use BuildsAdminFieldMetadata;

    public function __construct(
        private readonly ContributionEntityState $contributionEntityMutationService,
        private readonly InspirationAdminContract $inspirationAdmin,
        private readonly ReportAdminContract $reportAdmin,
        private readonly SaveAddressAreaAction $saveAddressAreaAction,
        private readonly AdminEventWriter $adminEventWriter,
        private readonly EventAuthoring $eventAuthoring,
        private readonly SeriesAuthoring $seriesAuthoring,
        private readonly AdminMutationHookRegistry $hooks,
    ) {}

    /** @param array<string, mixed> $data */
    private function persistAdminEvent(array $data, User $actor, ?Event $event = null): Event
    {
        $id = $this->adminEventWriter->save($data, (string) $actor->getKey(), $event?->getKey());

        return Event::query()->with(['accessPolicy', 'references', 'series', 'classifications', 'keyPeople', 'languages', 'media', 'institution', 'venue', 'primaryLocation.venueSpace'])->findOrFail($id);
    }

    /**
     * @param  class-string  $resourceClass
     */
    public function supports(string $resourceClass): bool
    {
        return $this->isReportResource($resourceClass)
            || $this->isInspirationResource($resourceClass)
            || $this->hooks->for($resourceClass) !== null
            || in_array($resourceClass, [
                AddressAreaResource::class,
                EventResource::class,
                SeriesResource::class,
            ], true);
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    public function schema(string $resourceClass, string $resourceKey, string $operation = 'create', ?Model $record = null): array
    {
        $updating = $operation === 'update';
        $defaults = $updating && $record instanceof Model
            ? $this->defaultsForRecord($record)
            : $this->defaultsForCreate($resourceClass);

        if ($this->isReportResource($resourceClass)) {
            return [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'not_applicable',
                'defaults' => $defaults,
                'current_media' => $record instanceof Model && $record instanceof HasMedia ? $this->mediaState($record, ['evidence']) : null,
                'fields' => $this->reportAdmin->fields(),
                'catalogs' => [],
                'conditional_rules' => [
                    ['field' => 'description', 'required_when' => ['category' => ['other']]],
                ],
            ];
        }

        if ($this->isInspirationResource($resourceClass)) {
            return $this->inspirationSchema($resourceKey, $operation, $record, $defaults);
        }

        $hook = $this->hooks->for($resourceClass);

        if ($hook !== null) {
            $schema = $hook->schema($resourceClass, $resourceKey, $operation, $record, $defaults);

            if ($schema !== null) {
                return $schema;
            }
        }

        return match ($resourceClass) {
            AddressAreaResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'application/json',
                'slug_behavior' => 'auto_managed',
                'defaults' => $defaults,
                'fields' => $this->addressAreaFields($updating),
                'catalogs' => [
                    $this->catalog('country_id', route('api.admin.catalogs.countries', [], false)),
                ],
                'conditional_rules' => [],
            ],
            EventResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'auto_managed',
                'defaults' => $defaults,
                'current_media' => $record instanceof Event ? $this->mediaState($record, ['cover', 'poster', 'gallery']) : null,
                'fields' => $this->eventFields($updating),
                'catalogs' => [
                    $this->catalog('submission_country_id', route('api.admin.catalogs.countries', [], false)),
                ],
                'conditional_rules' => [
                    ['field' => 'custom_time', 'required_when' => ['prayer_time' => [EventPrayerTime::LainWaktu->value]]],
                ],
            ],
            SeriesResource::class => [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'multipart/form-data',
                'slug_behavior' => 'user_provided',
                'defaults' => $defaults,
                'current_media' => $record instanceof Series ? $this->mediaState($record, ['cover', 'gallery']) : null,
                'fields' => $this->seriesFields(),
                'catalogs' => [],
                'conditional_rules' => [],
            ],
            default => throw new \RuntimeException('Unsupported admin write resource.'),
        };
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    public function rules(string $resourceClass, bool $updating = false): array
    {
        if ($this->isReportResource($resourceClass)) {
            return $this->reportAdmin->rules($updating);
        }

        if ($this->isInspirationResource($resourceClass)) {
            return $this->inspirationAdmin->rules($updating);
        }

        $hook = $this->hooks->for($resourceClass);

        if ($hook !== null && ($rules = $hook->rules($resourceClass, $updating)) !== null) {
            return $rules;
        }

        return match ($resourceClass) {
            AddressAreaResource::class => $this->addressAreaRules($updating),
            EventResource::class => $this->eventRules($updating),
            SeriesResource::class => $this->seriesRules($updating),
            default => [],
        };
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeValidatedPayload(string $resourceClass, array $validated, ?Model $record = null): array
    {
        $hook = $this->hooks->for($resourceClass);

        if ($hook !== null) {
            $validated = $hook->normalize($resourceClass, $validated, $record);
        }

        if ($this->isReportResource($resourceClass)) {
            return $this->reportAdmin->normalize(
                $validated,
                $record instanceof Model ? (string) $record->getKey() : null,
            );
        }

        if ($this->isInspirationResource($resourceClass)) {
            return $this->inspirationAdmin->normalize(
                $validated,
                $record instanceof Model ? (string) $record->getKey() : null,
            );
        }

        return $validated;
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function store(string $resourceClass, array $validated, User $actor): Model
    {
        if ($this->isReportResource($resourceClass)) {
            return $this->saveReport($validated);
        }

        if ($this->isInspirationResource($resourceClass)) {
            return $this->saveInspiration($resourceClass, $validated);
        }

        $hook = $this->hooks->for($resourceClass);

        if ($hook !== null && ($record = $hook->store($resourceClass, $validated, $actor)) !== null) {
            return $record;
        }

        return match ($resourceClass) {
            AddressAreaResource::class => $this->saveAddressAreaAction->handle($validated),
            EventResource::class => $this->persistAdminEvent($validated, $actor),
            SeriesResource::class => Series::query()->with(['languages', 'media'])->findOrFail($this->seriesAuthoring->save($validated)),
            default => throw new \RuntimeException('Unsupported admin write resource.'),
        };
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function update(string $resourceClass, Model $record, array $validated, User $actor): Model
    {
        if ($this->isReportResource($resourceClass)) {
            return $this->saveReport($validated, (string) $record->getKey());
        }

        if ($this->isInspirationResource($resourceClass)) {
            if (! $this->isInspirationModel($record)) {
                throw new \RuntimeException('Expected inspiration record.');
            }

            return $this->saveInspiration($resourceClass, $validated, (string) $record->getKey());
        }

        $hook = $this->hooks->for($resourceClass);

        if ($hook !== null && ($updated = $hook->update($resourceClass, $record, $validated, $actor)) !== null) {
            return $updated;
        }

        return match ($resourceClass) {
            AddressAreaResource::class => $record instanceof AddressArea
                ? $this->saveAddressAreaAction->handle($validated, $record)
                : throw new \RuntimeException('Expected address area record.'),
            EventResource::class => $record instanceof Event
                ? $this->persistAdminEvent($validated, $actor, $record)
                : throw new \RuntimeException('Expected event record.'),
            SeriesResource::class => $record instanceof Series
                ? Series::query()->with(['languages', 'media'])->findOrFail($this->seriesAuthoring->save($validated, (string) $record->getKey()))
                : throw new \RuntimeException('Expected series record.'),
            default => throw new \RuntimeException('Unsupported admin write resource.'),
        };
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{normalized_payload: array<string, mixed>, warnings: list<array<string, string>>, destructive_media_fields: list<string>}
     */
    public function previewNormalizedPayload(array $normalizedPayload): array
    {
        $destructiveMediaFields = $this->destructiveMediaFields($normalizedPayload);

        return [
            'normalized_payload' => $this->previewPayload($normalizedPayload),
            'warnings' => array_values(array_map(
                fn (string $field): array => [
                    'code' => 'destructive_media_clear',
                    'field' => $field,
                    'message' => $this->destructiveMediaWarning($field),
                ],
                $destructiveMediaFields,
            )),
            'destructive_media_fields' => $destructiveMediaFields,
        ];
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    private function defaultsForCreate(string $resourceClass): array
    {
        if ($this->isReportResource($resourceClass)) {
            return $this->reportAdmin->createDefaults();
        }

        if ($this->isInspirationResource($resourceClass)) {
            return $this->inspirationAdmin->createDefaults();
        }

        $hook = $this->hooks->for($resourceClass);

        if ($hook !== null && ($defaults = $hook->defaultsForCreate($resourceClass)) !== null) {
            return $defaults;
        }

        return match ($resourceClass) {
            AddressAreaResource::class => [
                'country_id' => null,
                'parent_id' => null,
                'type' => 'area',
                'level' => 1,
                'name' => '',
                'native_name' => null,
                'code' => null,
                'latitude' => null,
                'longitude' => null,
                'source' => 'manual',
                'source_id' => null,
                'parent_source_id' => null,
            ],
            EventResource::class => $this->eventAuthoring->adminFormDefaults(),
            SeriesResource::class => [
                'title' => '',
                'slug' => '',
                'description' => null,
                'visibility' => 'public',
                'status' => 'active',
                'languages' => [],
                'clear_cover' => false,
                'clear_gallery' => false,
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsForRecord(Model $record): array
    {
        $defaults = in_array($record->getMorphClass(), ['institution', 'person', 'reference', 'event'], true)
            ? $this->contributionEntityMutationService->stateFor($record->getMorphClass(), (string) $record->getKey())
            : [];

        if ($record instanceof AddressArea) {
            $defaults = [
                'country_id' => $record->country_id,
                'parent_id' => $record->parent_id,
                'type' => $record->type,
                'level' => $record->level,
                'name' => $record->name,
                'native_name' => $record->native_name,
                'code' => $record->code,
                'latitude' => $record->latitude,
                'longitude' => $record->longitude,
                'source' => $record->source,
                'source_id' => $record->source_id,
                'parent_source_id' => $record->parent_source_id,
            ];
        }

        if ($this->isInspirationModel($record)) {
            $defaults = $this->inspirationAdmin->defaultsFor((string) $record->getKey());
        }

        if ($this->isReportModel($record)) {
            $defaults = $this->reportAdmin->defaultsFor((string) $record->getKey());
        }

        if ($record instanceof Series) {
            $defaults = [
                'title' => $record->title,
                'slug' => $record->slug,
                'description' => $record->description,
                'visibility' => (string) $record->visibility,
                'status' => (string) $record->status,
                'languages' => $record->languages()->pluck('languages.id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                'clear_cover' => false,
                'clear_gallery' => false,
            ];
        }

        if ($record instanceof Event) {
            $defaults = $this->eventAuthoring->adminFormState((string) $record->getKey());
        }

        foreach ($this->hooks->all() as $hooks) {
            $defaults = $hooks->augmentDefaultsForRecord($record, $defaults);
        }

        return $defaults;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function saveReport(array $validated, ?string $reportId = null): Model
    {
        $snapshot = $this->reportAdmin->save($validated, $reportId);

        return $this->reportAdmin->modelClass()::query()
            ->with(['entity', 'reporter', 'handler', 'media'])
            ->findOrFail($snapshot->id);
    }

    /**
     * @param  class-string  $resourceClass
     */
    private function isReportResource(string $resourceClass): bool
    {
        return $resourceClass::getModel() === $this->reportAdmin->modelClass();
    }

    private function isReportModel(Model $record): bool
    {
        return $record::class === $this->reportAdmin->modelClass();
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function inspirationSchema(string $resourceKey, string $operation, ?Model $record, array $defaults): array
    {
        $updating = $operation === 'update';

        return [
            'resource_key' => $resourceKey,
            'operation' => $operation,
            'method' => $updating ? 'PUT' : 'POST',
            'endpoint' => $updating && $record instanceof Model
                ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
            'content_type' => 'multipart/form-data',
            'slug_behavior' => 'not_applicable',
            'defaults' => $defaults,
            'current_media' => $record instanceof Model && $record instanceof HasMedia
                ? $this->mediaState($record, $this->inspirationAdmin->mediaCollections())
                : null,
            'fields' => $this->inspirationAdmin->fields(),
            'catalogs' => [],
            'conditional_rules' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function saveInspiration(string $resourceClass, array $validated, ?string $inspirationId = null): Model
    {
        $snapshot = $this->inspirationAdmin->save($validated, $inspirationId);
        $modelClass = $resourceClass::getModel();

        return $modelClass::query()->with(['media'])->findOrFail($snapshot->id);
    }

    /**
     * @param  class-string  $resourceClass
     */
    private function isInspirationResource(string $resourceClass): bool
    {
        return $resourceClass::getModel() === $this->inspirationAdmin->modelClass();
    }

    private function isInspirationModel(Model $record): bool
    {
        return $record::class === $this->inspirationAdmin->modelClass();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function addressAreaFields(bool $updating): array
    {
        return [
            $this->field('country_id', 'uuid', required: ! $updating, meta: [
                'mutation_semantics' => 'replace_scalar',
                'relation' => 'address-countries',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'explicit_null' => 'invalid_country_selection',
                ],
            ]),
            $this->field('parent_id', 'uuid', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'relation' => 'address-areas',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'explicit_null' => 'clear_to_null',
                ],
            ]),
            $this->field('type', 'string', required: ! $updating, maxLength: 100, meta: $this->trimmedStringMutationMeta()),
            $this->field('level', 'integer', required: false, meta: [
                'input_type' => 'integer',
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'explicit_null' => 'clear_to_null',
                ],
                'normalization' => [
                    'integer_cast' => true,
                    'minimum' => 1,
                    'maximum' => 10,
                ],
            ]),
            $this->field('name', 'string', required: ! $updating, maxLength: 255, meta: [
                'mutation_semantics' => 'replace_scalar',
                'normalization' => ['trim' => true],
            ]),
            $this->field('native_name', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('code', 'string', required: false, maxLength: 100, meta: $this->trimmedStringMutationMeta()),
            $this->field('latitude', 'number', required: false, meta: [
                'input_type' => 'decimal',
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'explicit_null' => 'clear_to_null',
                ],
            ]),
            $this->field('longitude', 'number', required: false, meta: [
                'input_type' => 'decimal',
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'explicit_null' => 'clear_to_null',
                ],
            ]),
            $this->field('source', 'string', required: false, maxLength: 100, meta: array_merge(
                $this->trimmedStringMutationMeta(),
                ['default_when_omitted_on_create' => 'manual']
            )),
            $this->field('source_id', 'string', required: false, maxLength: 255, meta: array_merge(
                $this->trimmedStringMutationMeta(),
                ['default_when_omitted_on_create' => 'generated_from_country_type_name']
            )),
            $this->field('parent_source_id', 'string', required: false, maxLength: 255, meta: array_merge(
                $this->trimmedStringMutationMeta(),
                ['default_when_omitted' => 'parent.source_id']
            )),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function seriesFields(): array
    {
        return [
            $this->field('title', 'string', required: true, maxLength: 255),
            $this->field('slug', 'string', required: true, maxLength: 255, meta: [
                'mutation_semantics' => 'replace_scalar',
                'normalization' => ['trim' => true],
                'uniqueness_scope' => 'series.slug',
            ]),
            $this->field('description', 'string', required: false, maxLength: 5000, meta: $this->trimmedStringMutationMeta()),
            $this->field('visibility', 'string', required: true, default: 'public', allowedValues: ['public', 'unlisted', 'private']),
            $this->field('status', 'string', required: false, default: 'active', allowedValues: ['active', 'inactive']),
            $this->field('languages', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'languages',
                submittedArray: 'replace_relation_sync',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_relation_ids',
            )),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];
    }

    /**
     /**
     * @return array<int, array<string, mixed>>
     */
    private function eventFields(bool $updating): array
    {
        $fields = [
            $this->field('title', 'string', required: ! $updating, maxLength: 255),
            $this->field('description', 'rich_text', required: false),
            $this->field('event_date', 'date', required: ! $updating),
            $this->field('prayer_time', 'string', required: ! $updating, default: EventPrayerTime::LainWaktu->value, allowedValues: $this->enumValues(EventPrayerTime::class)),
            $this->field('custom_time', 'string', required: false, maxLength: 32),
            $this->field('end_time', 'string', required: false, maxLength: 32),
            $this->field('timezone', 'string', required: ! $updating, default: 'Asia/Kuala_Lumpur', maxLength: 64),
            $this->field('event_format', 'string', required: ! $updating, default: EventFormat::Physical->value, allowedValues: $this->enumValues(EventFormat::class)),
            $this->field('visibility', 'string', required: ! $updating, default: EventVisibility::Public->value, allowedValues: $this->enumValues(EventVisibility::class)),
            $this->field('event_url', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(omitted: 'preserve_existing_via_server_state_merge')),
            $this->field('live_url', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(omitted: 'preserve_existing_via_server_state_merge')),
            $this->field('recording_url', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta(omitted: 'preserve_existing_via_server_state_merge')),
            $this->field('gender', 'string', required: ! $updating, default: EventGenderRestriction::All->value, allowedValues: $this->enumValues(EventGenderRestriction::class)),
            $this->field('age_group', 'array<string>', required: ! $updating, allowedValues: $this->enumValues(EventAgeGroup::class), meta: [
                'collection_semantics' => $this->replaceCollectionSemantics(
                    explicitNull: 'invalid_type',
                    emptyArray: 'invalid_minimum_size',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_age_group_array',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
            ]),
            $this->field('children_allowed', 'boolean', required: false, default: false),
            $this->field('is_muslim_only', 'boolean', required: false, default: false),
            $this->field('languages', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'languages',
                submittedArray: 'replace_relation_sync',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_relation_ids',
                omitted: 'preserve_existing_collection_via_server_state_merge',
            )),
            $this->field('event_category_ids', 'array<uuid>', required: ! $updating, allowedValues: array_keys(app(EventCategoryCatalog::class)->options()), meta: [
                'collection_semantics' => $this->replaceCollectionSemantics(
                    explicitNull: 'invalid_type',
                    emptyArray: 'invalid_minimum_size',
                    itemIdsPreserved: null,
                    ordering: null,
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_category_ids_array',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
            ]),
            $this->field('domain_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'classifications',
                    submittedArray: 'replace_relation_sync',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_term_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['taxonomy_code' => EventTaxonomyCode::Domain->value],
            )),
            $this->field('discipline_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'classifications',
                    submittedArray: 'replace_relation_sync',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_term_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['taxonomy_code' => EventTaxonomyCode::Discipline->value],
            )),
            $this->field('source_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'classifications',
                    submittedArray: 'replace_relation_sync',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_term_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['taxonomy_code' => EventTaxonomyCode::Source->value],
            )),
            $this->field('issue_tags', 'array<string>', required: false, meta: array_merge(
                $this->relationCollectionMeta(
                    'classifications',
                    submittedArray: 'replace_relation_sync',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_event_term_ids',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                ['taxonomy_code' => EventTaxonomyCode::Issue->value],
            )),
            $this->field('references', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'references',
                submittedArray: 'replace_relation_sync',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_reference_ids',
                omitted: 'preserve_existing_collection_via_server_state_merge',
            )),
            $this->field('series', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'series',
                submittedArray: 'replace_relation_sync',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_series_ids',
                omitted: 'preserve_existing_collection_via_server_state_merge',
            )),
            $this->field('primary_organizer_id', 'string', required: ! $updating, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'invalid_required_value',
                ],
                'accepted_models' => [
                    \App\Shared\Support\MorphAliasModel::classFor('institution'),
                    \App\Shared\Support\MorphAliasModel::classFor('person'),
                ],
            ]),
            $this->field('institution_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'exclusive_with' => ['venue_id'],
            ]),
            $this->field('venue_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'exclusive_with' => ['institution_id', 'space_id'],
            ]),
            $this->field('submission_country_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'relation' => 'address-countries',
            ]),
            $this->field('space_id', 'string', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                    'explicit_null' => 'clear_to_null',
                ],
                'requires' => ['institution_id'],
                'prohibited_with' => ['venue_id'],
            ]),
            $this->field('persons', 'array<string>', required: false, meta: [
                'relation' => 'key_people',
                'subset_scope' => 'persons',
                'collection_semantics' => $this->replaceCollectionSemantics(
                    submittedArray: 'replace_person_subset_and_rebuild_key_people',
                    itemIdsPreserved: false,
                    ordering: 'payload_order_sets_order_column_before_other_key_people',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_person_list',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
            ]),
            $this->field('other_key_people', 'array<object>', required: false, meta: [
                'relation' => 'key_people',
                'subset_scope' => 'other_key_people',
                'collection_semantics' => $this->replaceCollectionSemantics(
                    submittedArray: 'replace_other_key_people_subset_and_rebuild_key_people',
                    itemIdsPreserved: false,
                    ordering: 'payload_order_sets_order_column_after_persons',
                    safeClientStrategy: 'omit_field_to_preserve_or_send_full_other_key_people_array',
                    omitted: 'preserve_existing_collection_via_server_state_merge',
                ),
                'item_schema' => $this->eventKeyPersonItemSchema(),
            ]),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: [
                ...$this->singleMediaFieldMutationMeta('clear_cover'),
                'required_aspect_ratio' => '16:9',
                'media_role' => 'website_app_cover',
            ]),
            $this->field('poster', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: [
                ...$this->singleMediaFieldMutationMeta('clear_poster'),
                'required_aspect_ratio' => '3:4',
                'media_role' => 'external_distribution_poster',
            ]),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 10),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_poster', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
            $this->field('registration_required', 'boolean', required: false, default: false),
            $this->field('registration_mode', 'string', required: false, default: RegistrationScope::Event->value, allowedValues: $this->enumValues(RegistrationScope::class), meta: [
                'mutation_semantics' => 'replace_setting_with_runtime_lock',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing_via_server_state_merge',
                ],
                'lock_behavior' => [
                    'when_event_has_registrations' => 'retain_current_value',
                ],
            ]),
        ];

        if (! $updating) {
            $fields[] = $this->field('status', 'string', required: false, default: 'draft', allowedValues: ['draft', 'pending', 'approved']);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventKeyPersonItemSchema(): array
    {
        return [
            'type' => 'object',
            'required_fields' => ['role_code'],
            'at_least_one_of' => ['involveable_id', 'display_name'],
            'empty_or_invalid_entries_discarded' => true,
            'fields' => [
                $this->field('role_code', 'string', required: false, allowedValues: $this->enumValues(EventKeyPersonRole::class), meta: [
                    'required_with' => ['involveable_id', 'display_name'],
                    'disallowed_values' => [EventKeyPersonRole::Speaker->value],
                ]),
                $this->field('involveable_type', 'string', required: false, allowedValues: ['person'], meta: [
                    'required_with' => ['involveable_id'],
                ]),
                $this->field('involveable_id', 'string', required: false, meta: [
                    'required_without' => ['display_name'],
                ]),
                $this->field('display_name', 'string', required: false, maxLength: 255, meta: [
                    'required_without' => ['involveable_id'],
                ]),
                $this->field('visibility', 'string', required: false, default: 'public', allowedValues: ['public', 'private']),
                $this->field('notes', 'string', required: false, maxLength: 500),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressAreaRules(bool $updating): array
    {
        $countryTable = (new AddressCountry)->getTable();
        $areaTable = (new AddressArea)->getTable();
        $required = $updating ? 'sometimes' : 'required';

        return [
            'country_id' => [$required, 'uuid', Rule::exists($countryTable, 'id')],
            'parent_id' => ['nullable', 'uuid', Rule::exists($areaTable, 'id')],
            'type' => [$required, 'string', 'max:100'],
            'level' => ['nullable', 'integer', 'min:1', 'max:10'],
            'name' => [$required, 'string', 'max:255'],
            'native_name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'source' => ['nullable', 'string', 'max:100'],
            'source_id' => ['nullable', 'string', 'max:255'],
            'parent_source_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRules(bool $updating): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        $rules = [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable'],
            'event_date' => [$required, 'date'],
            'prayer_time' => [$required, Rule::enum(EventPrayerTime::class)],
            'custom_time' => ['nullable', 'string', 'max:32', 'required_if:prayer_time,'.EventPrayerTime::LainWaktu->value],
            'end_time' => ['nullable', 'string', 'max:32'],
            'timezone' => [$required, 'string', 'max:64'],
            'event_format' => [$required, Rule::enum(EventFormat::class)],
            'visibility' => [$required, Rule::enum(EventVisibility::class)],
            'event_url' => ['nullable', 'url', 'max:255'],
            'live_url' => ['nullable', 'url', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:255'],
            'gender' => [$required, Rule::enum(EventGenderRestriction::class)],
            'age_group' => [$required, 'array', 'min:1'],
            'age_group.*' => [Rule::enum(EventAgeGroup::class)],
            'children_allowed' => ['sometimes', 'boolean'],
            'is_muslim_only' => ['sometimes', 'boolean'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'exists:languages,id'],
            'event_category_ids' => [$required, 'array', 'min:1'],
            'event_category_ids.*' => ['uuid', Rule::in(array_keys(app(EventCategoryCatalog::class)->options()))],
            'domain_tags' => ['nullable', 'array'],
            'domain_tags.*' => $this->eventTermRules(EventTaxonomyCode::Domain),
            'discipline_tags' => ['nullable', 'array'],
            'discipline_tags.*' => $this->eventTermRules(EventTaxonomyCode::Discipline),
            'source_tags' => ['nullable', 'array'],
            'source_tags.*' => $this->eventTermRules(EventTaxonomyCode::Source),
            'issue_tags' => ['nullable', 'array'],
            'issue_tags.*' => $this->eventTermRules(EventTaxonomyCode::Issue),
            'references' => ['nullable', 'array'],
            'references.*' => ['uuid', 'exists:references,id'],
            'series' => ['nullable', 'array'],
            'series.*' => ['uuid', Rule::exists((new Series)->getTable(), 'id')],
            'primary_organizer_id' => [
                $required,
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (
                        \App\Shared\Support\MorphAliasModel::classFor('institution')::query()->whereKey($value)->exists()
                        || \App\Shared\Support\MorphAliasModel::classFor('person')::query()->whereKey($value)->exists()
                    ) {
                        return;
                    }

                    $fail(__('The selected primary organizer is invalid.'));
                },
            ],
            'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],
            'venue_id' => ['nullable', 'uuid', 'exists:venues,id'],
            'submission_country_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')],
            'space_ids' => ['nullable', 'array'],
            'space_ids.*' => ['uuid', 'exists:venue_spaces,id'],
            'persons' => ['nullable', 'array'],
            'persons.*' => ['uuid', 'exists:persons,id'],
            'other_key_people' => ['nullable', 'array'],
            'other_key_people.*.role_code' => ['required_with:other_key_people.*.display_name,other_key_people.*.involveable_id', Rule::enum(EventKeyPersonRole::class)],
            'other_key_people.*.involveable_type' => ['nullable', Rule::in(['person']), 'required_with:other_key_people.*.involveable_id'],
            'other_key_people.*.involveable_id' => ['nullable', 'uuid', 'exists:persons,id', 'required_without:other_key_people.*.display_name'],
            'other_key_people.*.display_name' => ['nullable', 'string', 'max:255', 'required_without:other_key_people.*.involveable_id'],
            'other_key_people.*.visibility' => ['sometimes', Rule::in(['public', 'private'])],
            'other_key_people.*.notes' => ['nullable', 'string', 'max:500'],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:ratio=16/9', $maxUploadSize],
            'poster' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:ratio=3/4', $maxUploadSize],
            'gallery' => ['nullable', 'array', 'max:10'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_poster' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
            'registration_required' => ['sometimes', 'boolean'],
            'registration_mode' => ['sometimes', Rule::enum(RegistrationScope::class)],
        ];

        if (! $updating) {
            $rules['status'] = ['sometimes', Rule::in(['draft', 'pending', 'approved'])];
        }

        return $rules;
    }

    /**
     * @return list<mixed>
     */
    private function eventTermRules(EventTaxonomyCode $taxonomy): array
    {
        $taxonomyId = EventTaxonomy::query()
            ->where('code', $taxonomy->value)
            ->value('id');

        return [
            'uuid',
            Rule::exists((new EventTerm)->getTable(), 'id')
                ->where('event_taxonomy_id', $taxonomyId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesRules(bool $updating): array
    {
        $required = $updating ? 'required' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'title' => [$required, 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visibility' => [$required, Rule::in(['public', 'unlisted', 'private'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'exists:languages,id'],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }
}
