<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Admin\Resources;

use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Persons\Enums\Gender;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Models\Person;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Platform\Api\Admin\Contracts\AdminMutationHooks;
use App\Platform\Ui\Forms\AddressFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class PersonMutationHooks implements AdminMutationHooks
{
    use BuildsAdminFieldMetadata;

    public function __construct(private PersonWorkflow $workflow) {}

    public function supports(string $resourceClass): bool
    {
        return $resourceClass === \App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource::class;
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
            'current_media' => $record instanceof Person ? $this->mediaState($record, ['avatar', 'main', 'cover', 'gallery']) : null,
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

        $addressRule = $updating ? ['sometimes', 'array'] : ['present', 'array'];
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'bio' => ['nullable', 'array'],
            'language_ids' => ['nullable', 'array'],
            'language_ids.*' => ['string', 'exists:languages,id'],
            'status' => ['required', Rule::in(['pending', 'verified', 'rejected', 'inactive'])],
            'allow_public_event_submission' => $updating ? ['sometimes', 'boolean'] : ['prohibited'],
            'address' => $addressRule,
            'address.country_id' => $updating
                ? ['nullable', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')]
                : ['required', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')],
            'address.area_assignments' => ['sometimes', 'array'],
            'address.area_assignments.*' => ['nullable', 'uuid', Rule::exists(ModelResolver::areaClass(), 'id')],
            'address.state_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::stateClass(), 'id')],
            'address.line1' => ['prohibited'],
            'address.line2' => ['prohibited'],
            'address.postcode' => ['prohibited'],
            'address.latitude' => ['prohibited'],
            'address.longitude' => ['prohibited'],
            'address.google_maps_url' => ['prohibited'],
            'address.google_place_id' => ['prohibited'],
            'address.google_feature_id' => ['prohibited'],
            'address.google_cid' => ['prohibited'],
            'address.google_entity_id' => ['prohibited'],
            'address.waze_url' => ['prohibited'],
            'contactMethods' => ['nullable', 'array'],
            'contactMethods.*.type' => ['required_with:contactMethods.*.value', Rule::enum(ContactMethodType::class)],
            'contactMethods.*.value' => ['required_with:contactMethods.*.type', 'string', 'max:255'],
            'contactMethods.*.purpose' => ['nullable', Rule::enum(ContactPurpose::class)],
            'contactMethods.*.is_public' => ['sometimes', 'boolean'],
            'social_media' => ['nullable', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.handle,social_media.*.url', Rule::enum(SocialPlatform::class)],
            'social_media.*.handle' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.handle'],
            'avatar' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'main' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'cover' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_avatar' => ['sometimes', 'boolean'],
            'clear_main' => ['sometimes', 'boolean'],
            'clear_cover' => ['sometimes', 'boolean'],
            'clear_gallery' => ['sometimes', 'boolean'],
        ];
    }

    public function normalize(string $resourceClass, array $validated, ?Model $record): array
    {
        if (! $this->supports($resourceClass) || ! is_array($validated['address'] ?? null)) {
            return $validated;
        }

        $countryProvided = AddressFields::countrySelectionProvided($validated['address']);
        $validated['address'] = AddressFields::prepareAddressPersistenceData($validated['address']);

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

        return Person::query()->findOrFail($this->workflow->saveFromAdmin($validated, (string) $actor->getKey()));
    }

    public function update(string $resourceClass, Model $record, array $validated, User $actor): ?Model
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        if (! $record instanceof Person) {
            throw new \RuntimeException('Expected person record.');
        }

        return Person::query()->findOrFail(
            $this->workflow->saveFromAdmin($validated, (string) $actor->getKey(), (string) $record->getKey()),
        );
    }

    public function defaultsForCreate(string $resourceClass): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        return [
            'gender' => Gender::Male->value,
            'status' => 'verified',
            'clear_avatar' => false,
            'clear_main' => false,
            'clear_cover' => false,
            'clear_gallery' => false,
        ];
    }

    public function augmentDefaultsForRecord(Model $record, array $defaults): array
    {
        if (! $record instanceof Person) {
            return $defaults;
        }

        $defaults['status'] = $record->status;
        $defaults['allow_public_event_submission'] = (bool) $record->allow_public_event_submission;
        $defaults['clear_avatar'] = false;
        $defaults['clear_main'] = false;
        $defaults['clear_cover'] = false;
        $defaults['clear_gallery'] = false;

        if (is_array($defaults['address'] ?? null)) {
            unset(
                $defaults['address']['line1'],
                $defaults['address']['line2'],
                $defaults['address']['postcode'],
                $defaults['address']['latitude'],
                $defaults['address']['longitude'],
                $defaults['address']['google_maps_url'],
                $defaults['address']['google_place_id'],
                $defaults['address']['google_feature_id'],
                $defaults['address']['google_cid'],
                $defaults['address']['google_entity_id'],
                $defaults['address']['waze_url'],
            );
        }

        return $defaults;
    }

    /** @return list<array<string, mixed>> */
    private function fields(bool $updating): array
    {
        $fields = [
            $this->field('name', 'string', required: true, maxLength: 255),
            $this->field('gender', 'string', required: true, default: Gender::Male->value, allowedValues: $this->enumValues(Gender::class)),
            $this->field('bio', 'rich_text', required: false),
            $this->field('language_ids', 'array<string>', required: false, meta: [
                'collection_semantics' => $this->replaceCollectionSemantics(
                    submittedArray: 'replace_relation_sync',
                    itemIdsPreserved: null,
                    ordering: null,
                ),
                'relation' => 'languages',
            ]),
            $this->field('status', 'string', required: true, allowedValues: ['pending', 'verified', 'rejected', 'inactive']),
            $this->field('address', 'object', required: ! $updating, meta: [
                'mutation_semantics' => 'deep_merge_when_present_visible_fields_only',
                'clear_semantics' => [
                    'omitted' => 'preserve_existing',
                    'empty_object' => 'invalid_without_country',
                    'explicit_null_children' => 'clear_supported_nullable_fields',
                ],
                'nested_field_omission' => 'preserve_existing_visible_fields',
                'prohibited_nested_fields' => [
                    'line1', 'line2', 'postcode', 'latitude', 'longitude',
                    'google_maps_url', 'google_place_id', 'google_feature_id',
                    'google_cid', 'google_entity_id', 'waze_url',
                ],
                'safe_client_strategy' => 'omit_address_to_preserve_or_resend_country_when_mutating',
            ]),
            $this->field('address.country_id', 'uuid', required: ! $updating, meta: [
                'required_on_create' => true,
                'required_on_update' => false,
                'required_when_parent_present_on_update' => true,
                'mutation_semantics' => 'replace_scalar',
                'clear_semantics' => ['omitted_on_update' => 'preserve_existing_if_address_omitted', 'explicit_null' => 'invalid_without_country'],
            ]),
            $this->field('address.area_assignments', 'array<object>', required: false),
            $this->field('contactMethods', 'array<object>', required: false, meta: $this->contactCollectionMeta()),
            $this->field('social_media', 'array<object>', required: false, meta: $this->socialMediaCollectionMeta()),
            $this->field('avatar', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('main', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: $this->singleMediaFieldMutationMeta('clear_main')),
            $this->field('cover', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('gallery', 'array<file>', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_avatar', 'boolean', required: false, default: false),
            $this->field('clear_main', 'boolean', required: false, default: false),
            $this->field('clear_cover', 'boolean', required: false, default: false),
            $this->field('clear_gallery', 'boolean', required: false, default: false),
        ];

        if ($updating) {
            $fields[] = $this->field('allow_public_event_submission', 'boolean', required: false);
        }

        return $fields;
    }
}
