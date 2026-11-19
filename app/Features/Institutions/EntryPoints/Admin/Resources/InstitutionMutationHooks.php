<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Admin\Resources;

use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Api\Enums\InstitutionType;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\Models\Institution;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Platform\Api\Admin\Contracts\AdminMutationHooks;
use App\Platform\Ui\Forms\SharedFormSchema;
use App\Shared\Directory\InstitutionFacilities;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class InstitutionMutationHooks implements AdminMutationHooks
{
    use BuildsAdminFieldMetadata;

    public function __construct(private InstitutionWorkflow $workflow) {}

    public function supports(string $resourceClass): bool
    {
        return $resourceClass === \App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\InstitutionResource::class;
    }

    public function schema(string $resourceClass, string $resourceKey, string $operation, ?Model $record, array $defaults): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        $updating = $operation === 'update';

        return [
            'resource_key' => $resourceKey,
            'operation' => $operation,
            'method' => $updating ? 'PUT' : 'POST',
            'endpoint' => $updating && $record instanceof Model
                ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
            'content_type' => 'multipart/form-data',
            'slug_behavior' => 'auto_managed',
            'defaults' => $defaults,
            'current_media' => $record instanceof Institution ? $this->mediaState($record, ['logo', 'cover', 'gallery']) : null,
            'fields' => $this->fields($updating),
            'catalogs' => $this->addressCatalogs('address'),
            'conditional_rules' => [],
        ];
    }

    public function rules(string $resourceClass, bool $updating): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        $addressRule = $updating ? ['sometimes', 'array'] : ['required', 'array'];
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'family_name' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::enum(InstitutionType::class)],
            'names' => ['nullable', 'array'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(InstitutionStatus::class)],
            'facilities' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value)) {
                    return;
                }

                foreach (InstitutionFacilities::validate($value) as $message) {
                    $fail($message);
                }
            }],
            'allow_public_event_submission' => $updating ? ['sometimes', 'boolean'] : ['prohibited'],
            'address' => $addressRule,
            'address.country_id' => $updating
                ? ['nullable', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')]
                : ['required', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')],
            'address.area_assignments' => ['sometimes', 'array'],
            'address.area_assignments.*' => ['nullable', 'uuid', Rule::exists(ModelResolver::areaClass(), 'id')],
            'address.state_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::stateClass(), 'id')],
            'address.line1' => ['nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.postcode' => ['nullable', 'string', 'max:16'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address.google_maps_url' => ['nullable', 'url', 'max:2048'],
            'address.google_place_id' => ['nullable', 'string', 'max:255'],
            'address.google_feature_id' => ['nullable', 'string', 'max:255'],
            'address.google_cid' => ['nullable', 'string', 'max:255'],
            'address.google_entity_id' => ['nullable', 'string', 'max:255'],
            'address.waze_url' => ['nullable', 'url', 'max:255'],
            'contactMethods' => ['nullable', 'array'],
            'contactMethods.*.type' => ['required_with:contactMethods.*.value', Rule::enum(ContactMethodType::class)],
            'contactMethods.*.value' => ['required_with:contactMethods.*.type', 'string', 'max:255'],
            'contactMethods.*.purpose' => ['nullable', Rule::enum(ContactPurpose::class)],
            'contactMethods.*.is_public' => ['sometimes', 'boolean'],
            'social_media' => ['nullable', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.handle,social_media.*.url', Rule::enum(SocialPlatform::class)],
            'social_media.*.handle' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.handle'],
            'logo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/svg+xml', $maxUploadSize],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_logo' => ['sometimes', 'boolean'],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }

    public function normalize(string $resourceClass, array $validated, ?Model $record): array
    {
        if (! $this->supports($resourceClass) || ! is_array($validated['address'] ?? null)) {
            return $validated;
        }

        $countryProvided = SharedFormSchema::countrySelectionProvided($validated['address']);
        $validated['address'] = SharedFormSchema::prepareAddressPersistenceData($validated['address']);

        if (! $countryProvided && $record instanceof Institution && is_string($record->primaryAddress()?->country_id)) {
            $validated['address']['country_id'] = $record->primaryAddress()->country_id;
            $countryProvided = true;
        }

        if (! $countryProvided) {
            throw ValidationException::withMessages([
                'address.country_id' => __('The address country is required.'),
            ]);
        }

        if (! is_string($validated['address']['country_id'] ?? null)) {
            throw ValidationException::withMessages([
                'address.country_id' => __('The selected country is invalid.'),
            ]);
        }

        return $validated;
    }

    public function store(string $resourceClass, array $validated, User $actor): ?Model
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        return Institution::query()->findOrFail($this->workflow->saveFromAdmin($validated, (string) $actor->getKey()));
    }

    public function update(string $resourceClass, Model $record, array $validated, User $actor): ?Model
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        if (! $record instanceof Institution) {
            throw new \RuntimeException('Expected institution record.');
        }

        return Institution::query()->findOrFail($this->workflow->saveFromAdmin($validated, (string) $actor->getKey(), (string) $record->getKey()));
    }

    public function defaultsForCreate(string $resourceClass): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        return [
            'type' => InstitutionType::Masjid->value,
            'status' => InstitutionStatus::Pending->value,
            'facilities' => null,
            'clear_logo' => false,
            'clear_cover' => false,
            'clear_gallery' => false,
        ];
    }

    public function augmentDefaultsForRecord(Model $record, array $defaults): array
    {
        if (! $record instanceof Institution) {
            return $defaults;
        }

        $defaults['status'] = $record->status->value;
        $defaults['facilities'] = $record->facilities;
        $defaults['allow_public_event_submission'] = (bool) $record->allow_public_event_submission;
        $defaults['clear_logo'] = false;
        $defaults['clear_cover'] = false;
        $defaults['clear_gallery'] = false;

        return $defaults;
    }

    /** @return list<array<string, mixed>> */
    private function fields(bool $updating): array
    {
        $fields = [
            $this->field('name', 'string', required: true, maxLength: 255),
            $this->field('middle_name', 'string', required: false, maxLength: 100),
            $this->field('family_name', 'string', required: false, maxLength: 100),
            $this->field('names', 'array', required: false, meta: [
                'mutation_semantics' => 'replace_collection',
                'item_schema' => [
                    'type' => 'object',
                    'fields' => [
                        ['name' => 'full_name', 'type' => 'string', 'required' => true, 'max_length' => 255],
                        ['name' => 'name_type', 'type' => 'string', 'required' => false, 'allowed_values' => ['official', 'nickname', 'abbreviation', 'local', 'historical']],
                        ['name' => 'language_code', 'type' => 'string', 'required' => false, 'default' => 'ms'],
                        ['name' => 'is_primary', 'type' => 'boolean', 'required' => false, 'default' => false],
                    ],
                ],
            ]),
            $this->field('type', 'string', required: true, default: InstitutionType::Masjid->value, allowedValues: $this->enumValues(InstitutionType::class)),
            $this->field('description', 'string', required: false),
            $this->field('status', 'string', required: true, allowedValues: $this->enumValues(InstitutionStatus::class)),
            $this->field('facilities', 'object', required: false, allowedValues: InstitutionFacilities::codes(), meta: [
                'mutation_semantics' => 'replace_map_when_present',
                'clear_semantics' => ['omitted' => 'preserve_existing', 'explicit_null' => 'clear_to_null', 'empty_object' => 'clear_to_null'],
                'value_schema' => ['type' => 'object', 'keys' => InstitutionFacilities::codes(), 'values' => 'boolean'],
                'unknown_keys' => 'rejected',
                'safe_client_strategy' => 'omit_field_to_preserve_or_send_full_flag_map',
            ]),
            $this->field('address', 'object', required: ! $updating, meta: [
                'mutation_semantics' => 'deep_merge_when_present',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'empty_object' => $updating ? 'preserve_existing_when_record_has_address' : 'invalid_without_country',
                    'explicit_null_children' => 'clear_supported_nullable_fields',
                ],
                'safe_client_strategy' => 'send_only_nested_fields_that_should_change',
                'nested_field_omission' => 'preserve_existing',
            ]),
            $this->field('address.country_id', 'uuid', required: ! $updating, meta: [
                'required_on_create' => true,
                'required_on_update' => false,
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => ['omitted_on_update' => 'preserve_existing_if_available', 'explicit_null' => 'invalid_without_existing_country'],
            ]),
            $this->field('address.area_assignments', 'array<object>', required: false),
            $this->field('contactMethods', 'array<object>', required: false, meta: $this->contactCollectionMeta()),
            $this->field('social_media', 'array<object>', required: false, meta: $this->socialMediaCollectionMeta()),
            $this->field('logo', 'file', required: false, acceptedMimeTypes: $this->logoMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_logo', 'boolean', required: false, default: false),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];

        if ($updating) {
            $fields[] = $this->field('allow_public_event_submission', 'boolean', required: false);
        }

        return $fields;
    }
}
