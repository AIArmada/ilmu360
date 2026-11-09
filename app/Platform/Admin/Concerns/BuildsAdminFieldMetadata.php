<?php

declare(strict_types=1);

namespace App\Platform\Admin\Concerns;

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
}
