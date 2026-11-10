<?php

declare(strict_types=1);

namespace App\Features\Directory\Support;

use App\Shared\Directory\Enums\ReferenceType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalization helpers for reference payloads shared by Directory actions.
 *
 * Feature actions accept plain Eloquent models, so virtual part values are read
 * from the stored reference_parts payload instead of app-model methods.
 */
final class ReferencePayload
{
    public static function optionalString(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    public static function requiredString(mixed $value, string $fallback): string
    {
        return self::optionalString($value) ?? $fallback;
    }

    public static function referenceType(mixed $value): string
    {
        if ($value instanceof ReferenceType) {
            return $value->value;
        }

        if ($value instanceof BackedEnum) {
            return is_string($value->value) ? $value->value : ReferenceType::Book->value;
        }

        if (is_string($value) && ReferenceType::tryFrom($value) instanceof ReferenceType) {
            return $value;
        }

        return ReferenceType::Book->value;
    }

    public static function recordKind(Model $reference): string
    {
        return (string) $reference->getAttribute('record_kind');
    }

    public static function isRoot(Model $reference): bool
    {
        return self::recordKind($reference) === 'work';
    }

    public static function isPart(Model $reference): bool
    {
        return self::recordKind($reference) === 'part';
    }

    public static function isEdition(Model $reference): bool
    {
        return self::recordKind($reference) === 'edition';
    }

    public static function partType(Model $reference): ?string
    {
        return self::primaryPartEntry($reference)['type'] ?? null;
    }

    public static function partNumber(Model $reference): ?string
    {
        return self::primaryPartEntry($reference)['value'] ?? null;
    }

    public static function partLabel(Model $reference): ?string
    {
        return self::primaryPartEntry($reference)['label'] ?? null;
    }

    /**
     * @return array{type?: string|null, value?: string|null, label?: string|null}
     */
    private static function primaryPartEntry(Model $reference): array
    {
        $parts = $reference->getAttribute('reference_parts');

        if (is_string($parts)) {
            $decoded = json_decode($parts, true);
            $parts = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($parts)) {
            return [];
        }

        $first = $parts[0] ?? null;

        if (! is_array($first)) {
            return [];
        }

        return [
            'type' => self::optionalString($first['type'] ?? null),
            'value' => self::optionalString($first['value'] ?? null),
            'label' => self::optionalString($first['label'] ?? null),
        ];
    }
}
