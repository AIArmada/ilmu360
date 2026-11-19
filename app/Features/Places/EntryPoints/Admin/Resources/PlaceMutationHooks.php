<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Admin\Resources;

use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Events\Models\VenueSpaceType;
use App\Features\Institutions\Models\Institution;
use App\Features\Places\Api\SpaceWorkflow;
use App\Features\Places\Api\VenueWorkflow;
use App\Features\Places\Models\Space;
use App\Features\Places\Models\Venue;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Platform\Api\Admin\Contracts\AdminMutationHooks;
use App\Shared\Directory\Enums\VenueType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\Rule;

final readonly class PlaceMutationHooks implements AdminMutationHooks
{
    use BuildsAdminFieldMetadata;

    public function __construct(
        private SpaceWorkflow $spaceWorkflow,
        private VenueWorkflow $venueWorkflow,
    ) {}

    public function supports(string $resourceClass): bool
    {
        return in_array($resourceClass, [
            \App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource::class,
            \App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource::class,
        ], true);
    }

    public function schema(string $resourceClass, string $resourceKey, string $operation, ?Model $record, array $defaults): ?array
    {
        $updating = $operation === 'update';

        if ($resourceClass === \App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource::class) {
            return [
                'resource_key' => $resourceKey,
                'operation' => $operation,
                'method' => $updating ? 'PUT' : 'POST',
                'endpoint' => $updating && $record instanceof Model
                    ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                    : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
                'content_type' => 'application/json',
                'slug_behavior' => 'user_provided',
                'defaults' => $defaults,
                'fields' => $this->spaceFields(),
                'catalogs' => [],
                'conditional_rules' => [],
            ];
        }

        if ($resourceClass === \App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource::class) {
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
                'current_media' => $record instanceof Venue ? $this->mediaState($record, ['main', 'cover', 'gallery']) : null,
                'fields' => $this->venueFields($updating),
                'catalogs' => $this->addressCatalogs('address'),
                'conditional_rules' => [],
            ];
        }

        return null;
    }

    public function rules(string $resourceClass, bool $updating): ?array
    {
        return match ($resourceClass) {
            \App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource::class => $this->spaceRules(),
            \App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource::class => $this->venueRules($updating),
            default => null,
        };
    }

    public function normalize(string $resourceClass, array $validated, ?Model $record): array
    {
        return $validated;
    }

    public function store(string $resourceClass, array $validated, User $actor): ?Model
    {
        return match ($resourceClass) {
            \App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource::class => Space::query()->findOrFail($this->spaceWorkflow->saveFromAdmin($validated)),
            \App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource::class => Venue::query()->findOrFail($this->venueWorkflow->saveFromAdmin($validated)),
            default => null,
        };
    }

    public function update(string $resourceClass, Model $record, array $validated, User $actor): ?Model
    {
        if ($resourceClass === \App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource::class && $record instanceof Space) {
            return Space::query()->findOrFail($this->spaceWorkflow->saveFromAdmin($validated, (string) $record->getKey()));
        }

        if ($resourceClass === \App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource::class && $record instanceof Venue) {
            return Venue::query()->findOrFail($this->venueWorkflow->saveFromAdmin($validated, (string) $record->getKey()));
        }

        return null;
    }

    public function defaultsForCreate(string $resourceClass): ?array
    {
        if ($resourceClass === \App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource::class) {
            return [
                'type' => VenueType::Dewan->value,
                'status' => 'verified',
                'facilities' => [],
                'clear_main' => false,
                'clear_cover' => false,
                'clear_gallery' => false,
            ];
        }

        if ($resourceClass === \App\Features\Places\EntryPoints\Filament\Resources\Spaces\SpaceResource::class) {
            return [
                'name' => '',
                'slug' => '',
                'code' => null,
                'space_type' => null,
                'capacity' => null,
                'status' => 'active',
                'visibility' => 'public',
                'level' => null,
                'unit_no' => null,
                'block' => null,
                'wing' => null,
                'institutions' => [],
                'institution_space_overrides' => [],
            ];
        }

        return null;
    }

    public function augmentDefaultsForRecord(Model $record, array $defaults): array
    {
        if ($record instanceof Venue) {
            $defaults['status'] = $record->status;
            $defaults['visibility'] = (string) ($record->visibility ?? 'public');
            $defaults['clear_main'] = false;
            $defaults['clear_cover'] = false;
            $defaults['clear_gallery'] = false;

            return $defaults;
        }

        if ($record instanceof Space) {
            $slug = $record->getAttribute('slug');

            return [
                'name' => $record->name,
                'slug' => is_string($slug) ? $slug : '',
                'code' => $record->code,
                'space_type' => $record->space_type,
                'capacity' => $record->capacity,
                'status' => (string) $record->status,
                'visibility' => (string) ($record->visibility ?? 'public'),
                'level' => $record->level,
                'unit_no' => $record->unit_no,
                'block' => $record->block,
                'wing' => $record->wing,
                'institutions' => $record->institutions()->pluck('institutions.id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                'institution_space_overrides' => $record->institutions()
                    ->get()
                    ->map(fn (Institution $institution): array => [
                        'institution_id' => (string) $institution->getKey(),
                        'capacity' => ($pivot = $institution->getRelationValue('pivot')) instanceof Pivot
                            ? $pivot->getAttribute('capacity')
                            : null,
                    ])
                    ->filter(fn (array $override): bool => $override['capacity'] !== null)
                    ->values()
                    ->all(),
            ];
        }

        return $defaults;
    }

    /** @return list<array<string, mixed>> */
    private function spaceFields(): array
    {
        return [
            $this->field('name', 'string', required: true, maxLength: 255),
            $this->field('slug', 'string', required: true, maxLength: 255, meta: [
                'mutation_semantics' => 'replace_scalar',
                'normalization' => ['trim' => true],
                'uniqueness_scope' => 'venue_spaces.slug:venue_id',
            ]),
            $this->field('code', 'string', required: false, maxLength: 255),
            $this->field('space_type', 'string', required: false, allowedValues: VenueSpaceType::query()->where('is_active', true)->pluck('code')->all()),
            $this->field('capacity', 'integer', required: false, meta: [
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => ['omitted' => 'preserve_existing', 'explicit_null' => 'clear_to_null'],
                'normalization' => ['empty_string_at_mutation_layer' => 'null', 'integer_cast' => true, 'minimum' => 1],
            ]),
            $this->field('level', 'string', required: false, maxLength: 255),
            $this->field('unit_no', 'string', required: false, maxLength: 255),
            $this->field('block', 'string', required: false, maxLength: 255),
            $this->field('wing', 'string', required: false, maxLength: 255),
            $this->field('status', 'string', required: false, default: 'active', allowedValues: ['active', 'inactive']),
            $this->field('visibility', 'string', required: false, default: 'public', allowedValues: ['public', 'unlisted', 'private']),
            $this->field('institutions', 'array<string>', required: false, meta: $this->relationCollectionMeta(
                'institutions',
                submittedArray: 'replace_relation_sync',
                safeClientStrategy: 'omit_field_to_preserve_or_send_full_institution_ids',
            )),
            $this->field('institution_space_overrides', 'array<object>', required: false, meta: [
                'mutation_semantics' => 'replace_relation_pivot_payload',
                'clear_semantics' => ['omitted' => 'preserve_existing', 'empty_array' => 'clear_all_overrides'],
                'item_shape' => ['institution_id' => 'uuid', 'capacity' => 'integer|null'],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function venueFields(bool $updating): array
    {
        return [
            $this->field('name', 'string', required: ! $updating, maxLength: 255),
            $this->field('type', 'string', required: ! $updating, default: VenueType::Dewan->value, allowedValues: $this->enumValues(VenueType::class)),
            $this->field('status', 'string', required: ! $updating, default: 'verified', allowedValues: ['pending', 'verified', 'rejected', 'inactive']),
            $this->field('visibility', 'string', required: false, default: 'public', allowedValues: ['public', 'unlisted', 'private']),
            $this->field('facilities', 'array<string>', required: false, allowedValues: $this->venueFacilityValues(), meta: $this->facilitiesCollectionMeta()),
            $this->field('address', 'object', required: ! $updating, meta: [
                'mutation_semantics' => 'deep_merge_when_present',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'empty_object' => $updating ? 'delete_existing_address' : 'invalid_without_country',
                    'explicit_null_children' => 'clear_supported_nullable_fields',
                ],
                'safe_client_strategy' => 'fetch_current_record_before_editing_nested_address',
                'nested_field_omission' => 'preserve_existing',
            ]),
            $this->field('address.country_id', 'uuid', required: ! $updating, meta: [
                'required_on_create' => true,
                'required_on_update' => false,
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => ['omitted_on_update' => 'preserve_existing_if_available', 'explicit_null' => 'invalid_country_selection'],
            ]),
            $this->field('address.area_assignments', 'array<object>', required: false),
            $this->field('contactMethods', 'array<object>', required: false, meta: $this->contactCollectionMeta()),
            $this->field('social_media', 'array<object>', required: false, meta: $this->socialMediaCollectionMeta()),
            $this->field('main', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: $this->singleMediaFieldMutationMeta('clear_main')),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_main', 'boolean', required: false, default: false),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];
    }

    /** @return array<string, mixed> */
    private function spaceRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'space_type' => ['nullable', Rule::exists((new VenueSpaceType)->getTable(), 'code')->where('is_active', true)],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'visibility' => ['sometimes', Rule::in(['public', 'unlisted', 'private'])],
            'level' => ['nullable', 'string', 'max:255'],
            'unit_no' => ['nullable', 'string', 'max:255'],
            'block' => ['nullable', 'string', 'max:255'],
            'wing' => ['nullable', 'string', 'max:255'],
            'institutions' => ['nullable', 'array'],
            'institutions.*' => ['uuid', 'exists:institutions,id'],
            'institution_space_overrides' => ['nullable', 'array'],
            'institution_space_overrides.*.institution_id' => ['required', 'uuid', 'exists:institutions,id'],
            'institution_space_overrides.*.capacity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    private function venueRules(bool $updating): array
    {
        $addressRule = $updating ? ['sometimes', 'array'] : ['required', 'array'];
        $required = $updating ? 'sometimes' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(VenueType::class)],
            'status' => [$required, Rule::in(['pending', 'verified', 'rejected', 'inactive'])],
            'visibility' => ['sometimes', Rule::in(['public', 'unlisted', 'private'])],
            'facilities' => ['nullable', 'array'],
            'facilities.*' => ['string', Rule::in($this->venueFacilityValues())],
            'address' => $addressRule,
            'address.country_id' => [$updating ? 'sometimes' : 'required', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')],
            'address.area_assignments' => ['sometimes', 'array'],
            'address.area_assignments.*' => ['nullable', 'uuid', Rule::exists(ModelResolver::areaClass(), 'id')],
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
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'main' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_main' => ['sometimes', 'boolean'],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }
}
