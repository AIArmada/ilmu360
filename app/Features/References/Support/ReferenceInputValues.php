<?php

declare(strict_types=1);

namespace App\Features\References\Support;

use BackedEnum;

/** Strict scalar decoding shared by reference reads and pre-save normalization. */
final readonly class ReferenceInputValues
{
    /** @param array<string, mixed> $attributes */
    public static function optionalStringAttribute(array $attributes, string $key): ?string
    {
        return array_key_exists($key, $attributes)
            ? self::normalizeStringValue($attributes[$key])
            : null;
    }

    public static function normalizeStringValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    public static function nullableTrimmedString(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
