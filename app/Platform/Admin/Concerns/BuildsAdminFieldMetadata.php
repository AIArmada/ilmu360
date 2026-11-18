<?php

declare(strict_types=1);

namespace App\Platform\Admin\Concerns;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;

trait BuildsAdminFieldMetadata
{
    /**
     * @param  list<string|int>|null  $allowedValues
     * @param  list<string>|null  $acceptedMimeTypes
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function field(
        string $name,
        string $type,
        bool $required = false,
        mixed $default = null,
        ?int $maxLength = null,
        ?array $allowedValues = null,
        ?array $acceptedMimeTypes = null,
        ?int $maxFileSizeKb = null,
        ?int $maxFiles = null,
        array $meta = [],
    ): array {
        return array_merge(
            array_filter([
                'name' => $name,
                'type' => $type,
                'required' => $required,
                'default' => $default,
                'max_length' => $maxLength,
                'allowed_values' => $allowedValues,
                'accepted_mime_types' => $acceptedMimeTypes,
                'max_file_size_kb' => $maxFileSizeKb,
                'max_files' => $maxFiles,
            ], static fn (mixed $value): bool => $value !== null),
            array_filter($meta, static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function trimmedStringMutationMeta(
        string $omitted = 'preserve_existing',
        string $explicitNull = 'clear_to_null',
        ?string $emptyStringAtMutationLayer = 'null',
    ): array {
        return [
            'mutation_semantics' => 'replace_scalar',
            'clear_semantics' => [
                'omitted' => $omitted,
                'explicit_null' => $explicitNull,
            ],
            'normalization' => array_filter([
                'trim' => true,
                'empty_string_at_mutation_layer' => $emptyStringAtMutationLayer,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableRelationScalarMeta(string $relation): array
    {
        return [
            'relation' => $relation,
            'mutation_semantics' => 'replace_scalar',
            'clear_semantics' => [
                'omitted' => 'preserve_existing',
                'explicit_null' => 'clear_to_null',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function multipleMediaFieldMutationMeta(
        string $rawHttpClearFlag,
        string $omitted = 'preserve_existing_collection',
        string $explicitNull = 'preserve_existing_collection',
        string $emptyArray = 'clear_collection',
        string $submittedArray = 'replace_collection',
    ): array {
        return [
            'collection_semantics' => [
                'omitted' => $omitted,
                'explicit_null' => $explicitNull,
                'empty_array' => $emptyArray,
                'submitted_array' => $submittedArray,
                'item_ids_preserved' => false,
                'ordering' => 'payload_order_sets_media_order',
                'safe_client_strategy' => 'send_full_media_array_when_replacing',
            ],
            'raw_http_clear_flag' => $rawHttpClearFlag,
        ];
    }

    private function maxUploadSizeKb(): int
    {
        return (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @return list<string|int>
     */
    private function enumValues(string $enumClass): array
    {
        return array_map(
            static fn (BackedEnum $case): string|int => $case->value,
            $enumClass::cases(),
        );
    }

    /**
     * @param  Model&HasMedia  $record
     * @param  list<string>  $collections
     * @return array<string, mixed>
     */
    private function mediaState(Model $record, array $collections): array
    {
        $state = [];

        foreach ($collections as $collection) {
            $media = $record->getMedia($collection);

            $state[$collection] = $media
                ->map(fn ($item): array => [
                    'id' => (int) $item->getKey(),
                    'name' => $item->name,
                    'file_name' => $item->file_name,
                ])
                ->values()
                ->all();
        }

        return $state;
    }

    private function recordKey(Model $record): string
    {
        return (string) $record->getRouteKey();
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function catalog(string $field, string $endpoint, array $query = []): array
    {
        return array_filter([
            'field' => $field,
            'endpoint' => $endpoint,
            'query' => $query,
        ], static fn (mixed $value): bool => $value !== []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function addressCatalogs(string $prefix): array
    {
        return [
            $this->catalog($prefix.'.country_id', route('api.admin.catalogs.countries', [], false)),
            $this->catalog(
                $prefix.'.area_assignments.administrative_district',
                route('api.admin.catalogs.administrative-districts', [], false),
                ['country_id' => '{'.$prefix.'.country_id}'],
            ),
            $this->catalog(
                $prefix.'.area_assignments.administrative_subdivision',
                route('api.admin.catalogs.administrative-subdivisions', [], false),
                ['administrative_district' => '{'.$prefix.'.area_assignments.administrative_district}'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactCollectionMeta(): array
    {
        return [
            'collection_semantics' => $this->replaceCollectionSemantics(),
            'item_schema' => $this->contactItemSchema(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function socialMediaCollectionMeta(): array
    {
        return [
            'collection_semantics' => $this->replaceCollectionSemantics(),
            'input_normalization' => [
                'kind' => 'canonical_social_handle_storage',
                'handle_platforms' => $this->socialHandlePlatforms(),
                'canonical_storage' => [
                    'identifier_field' => 'handle',
                    'url_field' => 'url',
                    'handle_platform_url_storage' => 'may_be_null_after_normalization',
                ],
            ],
            'item_schema' => $this->socialMediaItemSchema(),
        ];
    }

    /**
     * @return list<string>
     */
    private function socialHandlePlatforms(): array
    {
        return [
            SocialPlatform::Facebook->value,
            SocialPlatform::X->value,
            SocialPlatform::Instagram->value,
            SocialPlatform::Youtube->value,
            SocialPlatform::Tiktok->value,
            SocialPlatform::Telegram->value,
            SocialPlatform::WhatsApp->value,
            SocialPlatform::Linkedin->value,
            SocialPlatform::Threads->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactItemSchema(): array
    {
        return [
            'type' => 'object',
            'paired_required_fields' => [
                ['type', 'value'],
            ],
            'fields' => [
                $this->field('type', 'string', required: false, allowedValues: $this->enumValues(ContactMethodType::class), meta: [
                    'required_with' => ['value'],
                ]),
                $this->field('value', 'string', required: false, maxLength: 255, meta: [
                    'required_with' => ['type'],
                    'used_for_types' => [
                        ContactMethodType::Phone->value,
                        ContactMethodType::Whatsapp->value,
                        ContactMethodType::Email->value,
                    ],
                ]),
                $this->field('purpose', 'string', required: false, default: ContactPurpose::General->value, allowedValues: $this->enumValues(ContactPurpose::class)),
                $this->field('is_public', 'boolean', required: false, default: true),
                $this->field('sort_order', 'integer', required: false, meta: [
                    'omitted' => 'assigned_from_payload_order_starting_at_1',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function socialMediaItemSchema(): array
    {
        return [
            'type' => 'object',
            'required_fields' => ['platform'],
            'at_least_one_of' => ['handle', 'url'],
            'fields' => [
                $this->field('platform', 'string', required: false, allowedValues: $this->enumValues(SocialPlatform::class), meta: [
                    'required_with' => ['handle', 'url'],
                ]),
                $this->field('handle', 'string', required: false, maxLength: 255, meta: [
                    'required_without' => ['url'],
                ]),
                $this->field('url', 'string', required: false, maxLength: 255, meta: [
                    'required_without' => ['handle'],
                    'format' => 'url',
                ]),
                $this->field('sort_order', 'integer', required: false, meta: [
                    'omitted' => 'assigned_from_payload_order_starting_at_1',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function facilitiesCollectionMeta(): array
    {
        return [
            'collection_semantics' => $this->replaceCollectionSemantics(
                itemIdsPreserved: null,
                ordering: 'not_applicable_set_semantics',
            ),
            'input_normalization' => [
                'kind' => 'facility_codes_to_relation',
                'storage_shape' => 'venue_facilities.facility_type_id',
                'replacement_scope' => 'general_venue_facilities_only',
                'unknown_codes' => 'rejected',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function relationCollectionMeta(
        string $relation,
        string $submittedArray = 'replace_relation_sync',
        ?bool $itemIdsPreserved = null,
        ?string $ordering = null,
        string $safeClientStrategy = 'omit_field_to_preserve_or_send_full_relation_ids',
        string $omitted = 'preserve_existing_collection',
    ): array {
        return [
            'relation' => $relation,
            'collection_semantics' => $this->replaceCollectionSemantics(
                submittedArray: $submittedArray,
                itemIdsPreserved: $itemIdsPreserved,
                ordering: $ordering,
                safeClientStrategy: $safeClientStrategy,
                omitted: $omitted,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function singleMediaFieldMutationMeta(string $rawHttpClearFlag): array
    {
        return [
            'mutation_semantics' => 'replace_single_media_collection',
            'clear_semantics' => [
                'omitted' => 'preserve_existing_collection',
                'explicit_null' => 'preserve_existing_collection',
                'submitted_file' => 'replace_collection',
            ],
            'raw_http_clear_flag' => $rawHttpClearFlag,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replaceCollectionSemantics(
        string $submittedArray = 'replace_collection',
        string $explicitNull = 'clear_collection',
        string $emptyArray = 'clear_collection',
        ?bool $itemIdsPreserved = false,
        ?string $ordering = 'payload_order_sets_order_column_when_missing',
        string $safeClientStrategy = 'fetch_modify_resend_full_collection',
        string $omitted = 'preserve_existing_collection',
    ): array {
        return array_filter([
            'omitted' => $omitted,
            'explicit_null' => $explicitNull,
            'empty_array' => $emptyArray,
            'submitted_array' => $submittedArray,
            'item_ids_preserved' => $itemIdsPreserved,
            'ordering' => $ordering,
            'safe_client_strategy' => $safeClientStrategy,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return list<string>
     */
    private function venueFacilityValues(): array
    {
        return [
            'parking',
            'oku',
            'women_section',
            'ablution_area',
        ];
    }

    /**
     * @return list<string>
     */
    private function imageMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * @return list<string>
     */
    private function logoMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function destructiveMediaFields(array $payload): array
    {
        $fields = [];

        foreach ($payload as $field => $value) {
            if (! is_string($field) || ! str_starts_with($field, 'clear_') || $value !== true) {
                continue;
            }

            $fields[] = $field;
        }

        return array_values(array_unique($fields));
    }

    private function destructiveMediaWarning(string $field): string
    {
        $label = ucwords(str_replace('_', ' ', substr($field, 6)));

        return __('The :field flag will clear the existing :label media collection before saving.', [
            'field' => $field,
            'label' => $label,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function previewPayload(array $payload): array
    {
        $preview = [];

        foreach ($payload as $key => $value) {
            $preview[$key] = $this->previewValue($value);
        }

        return $preview;
    }

    private function previewValue(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'file_name' => $value->getClientOriginalName(),
                'mime_type' => $value->getClientMimeType(),
                'size' => $value->getSize(),
            ];
        }

        if (is_array($value)) {
            return array_map($this->previewValue(...), $value);
        }

        return $value;
    }
}
