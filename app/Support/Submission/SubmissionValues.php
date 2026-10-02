<?php

declare(strict_types=1);

namespace App\Support\Submission;

use BackedEnum;

/**
 * Shared value helpers for the event-submission pipeline.
 *
 * Only genuinely identical semantics live here. The structural validator
 * keeps its own blank-to-default enum coercion (pre-validation
 * sanitization), and session persistence keeps its mixed enum passthrough
 * (model-attribute unwrap); those differ intentionally and must not be
 * merged into these post-validated helpers.
 */
final class SubmissionValues
{
    /**
     * Post-validated enum read: backed enums unwrap, strings pass through
     * as-is, scalars cast, anything else falls back to the default.
     */
    public static function enumValue(mixed $value, string $default = ''): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    public static function prefixedKey(string $field, string $validationKeyPrefix = ''): string
    {
        return $validationKeyPrefix === '' ? $field : $validationKeyPrefix.$field;
    }
}
