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
        return array_values(array_map(static function (mixed $row): array {
            $canonical = DonationPayoutNormalizer::canonicalRow($row);

            if ($canonical['currency'] === null && in_array($canonical['method'], ['duitnow', 'ewallet'], true)) {
                $canonical['currency'] = 'MYR';
            }

            return $canonical;
        }, array_values(array_filter($rows, is_array(...)))));
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
}
