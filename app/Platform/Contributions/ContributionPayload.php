<?php

declare(strict_types=1);

namespace App\Platform\Contributions;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;

/**
 * Projects submitted contribution payload fragments onto the canonical stored
 * shape so unchanged rows compare equal against persisted state.
 */
final class ContributionPayload
{
    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function normalizeContactRowsForComparison(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(self::normalizeContactRowForComparison(...), $data);
        }

        return self::normalizeContactRowForComparison($data);
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
        $typeValue = $type instanceof ContactMethodType
            ? $type->value
            : strtolower((string) $type);

        return in_array($typeValue, [ContactMethodType::Phone->value, ContactMethodType::Whatsapp->value, ContactMethodType::Mobile->value], true);
    }

    public static function normalizedContactValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    public static function normalizedComparablePhoneValue(mixed $value): ?string
    {
        $value = self::normalizedContactValue($value);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        return str_starts_with($value, '+') ? '+'.$digits : $digits;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    private static function normalizeContactRowForComparison(array $contact): array
    {
        $contact = self::normalizeContactRowForSave($contact);
        $contact = self::normalizeContactRowForFill($contact);

        if (! self::isPhoneContactType($contact['type'] ?? null)) {
            return $contact;
        }

        $value = self::normalizedComparablePhoneValue($contact['phone_value'] ?? ($contact['value'] ?? null));

        if ($value !== null) {
            $contact['phone_value'] = $value;
        }

        unset($contact['value']);

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    private static function normalizeContactRowForFill(array $contact): array
    {
        $contact['type'] ??= $contact['category'] ?? null;
        $contact['purpose'] ??= ContactPurpose::General->value;

        unset($contact['category']);

        if (self::isPhoneContactType($contact['type'] ?? null)) {
            $contact['phone_value'] = $contact['value'] ?? null;
        }

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    private static function normalizeContactRowForSave(array $contact): array
    {
        $contact['type'] ??= $contact['category'] ?? null;
        $contact['purpose'] ??= ContactPurpose::General->value;
        $type = $contact['type'] ?? null;

        unset($contact['category']);

        if (self::isPhoneContactType($type)) {
            $value = self::normalizedContactValue($contact['phone_value'] ?? $contact['value'] ?? null);

            if ($value !== null) {
                $contact['value'] = $value;
            }

            unset($contact['phone_value']);

            return $contact;
        }

        $value = self::normalizedContactValue($contact['value'] ?? null);

        if ($value !== null) {
            $contact['value'] = $value;
        }

        unset($contact['phone_value']);

        return $contact;
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
