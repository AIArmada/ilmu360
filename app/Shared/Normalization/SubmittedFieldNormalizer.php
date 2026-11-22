<?php

declare(strict_types=1);

namespace App\Shared\Normalization;

use AIArmada\FilamentContacting\Schemas\ContactMethodRepeaterSchema;

/**
 * Projects submitted contribution payload fragments onto the canonical stored
 * shape so unchanged rows compare equal against persisted state.
 */
final readonly class SubmittedFieldNormalizer
{
    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function normalizeContactRowsForComparison(array $data): array
    {
        return ContactMethodRepeaterSchema::normalizeRowsForComparison($data);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeDonationChannelRowsForComparison(array $rows): array
    {
        return array_values(array_map(static fn (mixed $row): array => [
            'label' => self::normalizedNullableString($row['label'] ?? null),
            'recipient' => self::normalizedNullableString($row['recipient'] ?? null),
            'method' => self::normalizedNullableString($row['method'] ?? null),
            'bank_code' => self::normalizedNullableString($row['bank_code'] ?? null),
            'bank_name' => self::normalizedNullableString($row['bank_name'] ?? null),
            'account_number' => self::normalizedNullableString($row['account_number'] ?? null),
            'duitnow_type' => self::normalizedNullableString($row['duitnow_type'] ?? null),
            'duitnow_value' => self::normalizedNullableString($row['duitnow_value'] ?? null),
            'ewallet_provider' => self::normalizedNullableString($row['ewallet_provider'] ?? null),
            'ewallet_handle' => self::normalizedNullableString($row['ewallet_handle'] ?? null),
            'ewallet_qr_payload' => self::normalizedNullableString($row['ewallet_qr_payload'] ?? null),
            'reference_note' => self::normalizedNullableString($row['reference_note'] ?? null),
            'is_default' => (bool) (is_array($row) ? ($row['is_default'] ?? false) : false),
        ], array_values(array_filter($rows, is_array(...)))));
    }

    public static function isPhoneContactType(mixed $type): bool
    {
        return ContactMethodRepeaterSchema::isPhoneContactType($type);
    }

    public static function normalizedContactValue(mixed $value): ?string
    {
        return ContactMethodRepeaterSchema::normalizedContactValue($value);
    }

    public static function normalizedComparablePhoneValue(mixed $value): ?string
    {
        return ContactMethodRepeaterSchema::normalizedComparablePhoneValue($value);
    }

    private static function normalizedNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
