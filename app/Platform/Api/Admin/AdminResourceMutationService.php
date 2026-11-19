<?php

namespace App\Platform\Api\Admin;

use AIArmada\Addressing\Actions\SaveAddressAreaAction;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\FilamentAddressing\Resources\AddressAreaResource;
use App\Features\Contributions\Api\ContributionEntityState;
use App\Features\Inspirations\Api\InspirationAdminContract;
use App\Features\Reports\Api\ReportAdminContract;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
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
        private readonly AdminMutationHookRegistry $hooks,
    ) {}

    /**
     * @param  class-string  $resourceClass
     */
    public function supports(string $resourceClass): bool
    {
        return $this->isReportResource($resourceClass)
            || $this->isInspirationResource($resourceClass)
            || $this->hooks->for($resourceClass) instanceof Contracts\AdminMutationHooks
            || $resourceClass === AddressAreaResource::class;
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

        if ($hook instanceof Contracts\AdminMutationHooks) {
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

        if ($hook instanceof Contracts\AdminMutationHooks && ($rules = $hook->rules($resourceClass, $updating)) !== null) {
            return $rules;
        }

        return match ($resourceClass) {
            AddressAreaResource::class => $this->addressAreaRules($updating),
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

        if ($hook instanceof Contracts\AdminMutationHooks) {
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

        if ($hook instanceof Contracts\AdminMutationHooks && ($record = $hook->store($resourceClass, $validated, $actor)) instanceof Model) {
            return $record;
        }

        return match ($resourceClass) {
            AddressAreaResource::class => $this->saveAddressAreaAction->handle($validated),
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

        if ($hook instanceof Contracts\AdminMutationHooks && ($updated = $hook->update($resourceClass, $record, $validated, $actor)) instanceof Model) {
            return $updated;
        }

        return match ($resourceClass) {
            AddressAreaResource::class => $record instanceof AddressArea
                ? $this->saveAddressAreaAction->handle($validated, $record)
                : throw new \RuntimeException('Expected address area record.'),
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

        if ($hook instanceof Contracts\AdminMutationHooks && ($defaults = $hook->defaultsForCreate($resourceClass)) !== null) {
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
}
