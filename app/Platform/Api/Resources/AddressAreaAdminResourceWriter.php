<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use AIArmada\Addressing\Features\Geography\Actions\SaveAddressAreaAction;
use AIArmada\Addressing\Features\Geography\Models\AddressArea;
use AIArmada\Addressing\Features\Geography\Models\AddressCountry;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Package-owned adapter for the Addressing address-area resource.
 */
final class AddressAreaAdminResourceWriter extends AbstractAdminResourceWriter
{
    use BuildsAdminFieldMetadata;

    public function __construct(
        ResourceDefinitionContext $context,
        string $resourceClass,
        private readonly SaveAddressAreaAction $saveAddressAreaAction,
        private readonly AdminMutationHookRegistry $hooks,
    ) {
        parent::__construct($context, $resourceClass);
    }

    /** @return array<string, mixed> */
    public function schemaFor(string $resourceKey, string $operation, ?Model $record): array
    {
        $updating = $operation === 'update';
        $defaults = $updating && $record instanceof Model
            ? $this->defaultsForRecord($record)
            : $this->defaultsForCreate();

        return [
            'resource_key' => $resourceKey,
            'operation' => $operation,
            'method' => $updating ? 'PUT' : 'POST',
            'endpoint' => $this->endpoint($resourceKey, $operation, $record),
            'content_type' => 'application/json',
            'slug_behavior' => 'auto_managed',
            'defaults' => $defaults,
            'fields' => $this->addressAreaFields($updating),
            'catalogs' => [
                $this->catalog('country_id', route('api.admin.catalogs.countries', [], false)),
            ],
            'conditional_rules' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function rulesFor(bool $updating): array
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
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeFor(array $validated, ?Model $record): array
    {
        return $validated;
    }

    /** @return array<string, mixed> */
    public function defaultsForCreate(): array
    {
        return [
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
        ];
    }

    /** @return array<string, mixed> */
    public function defaultsForRecord(Model $record): array
    {
        $defaults = $record instanceof AddressArea
            ? [
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
            ]
            : [];

        foreach ($this->hooks->all() as $hooks) {
            $defaults = $hooks->augmentDefaultsForRecord($record, $defaults);
        }

        return $defaults;
    }

    /** @param array<string, mixed> $validated */
    public function storeModel(User $actor, array $validated): Model
    {
        $id = $this->saveAddressAreaAction->handle($validated);

        return AddressArea::query()->findOrFail($id);
    }

    /** @param array<string, mixed> $validated */
    public function updateModel(User $actor, Model $record, array $validated): Model
    {
        if (! $record instanceof AddressArea) {
            throw new RuntimeException('Expected address area record.');
        }

        $id = $this->saveAddressAreaAction->handle($validated, (string) $record->getKey());

        return AddressArea::query()->findOrFail($id);
    }

    /** @return array<int, array<string, mixed>> */
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
}
