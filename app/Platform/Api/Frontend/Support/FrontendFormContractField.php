<?php

declare(strict_types=1);

namespace App\Platform\Api\Frontend\Support;

/**
 * Neutral field descriptor builder shared by form-contract contributors.
 *
 * The exact output shape is part of the public API contract: null optional
 * attributes are omitted and meta values are merged after the core keys.
 */
final class FrontendFormContractField
{
    /**
     * @param  class-string<\BackedEnum>  $enumClass
     * @return list<string|int>
     */
    public static function enumValues(string $enumClass): array
    {
        return array_map(static fn (\BackedEnum $enum): string|int => $enum->value, $enumClass::cases());
    }

    /**
     * @param  list<string|int>|null  $allowedValues
     * @param  list<string>|null  $acceptedMimeTypes
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function make(
        string $name,
        string $type,
        bool $required,
        mixed $default = null,
        ?int $maxLength = null,
        ?array $allowedValues = null,
        ?string $catalog = null,
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
                'catalog' => $catalog,
                'accepted_mime_types' => $acceptedMimeTypes,
                'max_file_size_kb' => $maxFileSizeKb,
                'max_files' => $maxFiles,
            ], static fn (mixed $value): bool => $value !== null),
            array_filter($meta, static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @return list<string>
     */
    public static function imageMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * @return list<string>
     */
    public static function evidenceMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    }

    public static function maxUploadSizeKb(): int
    {
        return (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
    }

    private function __construct() {}
}
