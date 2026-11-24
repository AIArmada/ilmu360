<?php

declare(strict_types=1);

namespace App\Shared\Normalization;

/**
 * Canonical donation payout fields shared by validation, reconciliation and
 * QR matching, so formatting differences (casing, trimmed whitespace, grouped
 * IBANs) never read as a different payout destination.
 */
final class DonationPayoutNormalizer
{
    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function canonicalRow(array $row): array
    {
        $method = self::string($row['method'] ?? null);

        return [
            'label' => self::string($row['label'] ?? null),
            'recipient' => self::string($row['recipient'] ?? null),
            'method' => $method === null ? null : mb_strtolower($method),
            'currency' => self::currency($row['currency'] ?? null),
            'bank_code' => self::string($row['bank_code'] ?? null),
            'bank_name' => self::string($row['bank_name'] ?? null),
            'account_number' => self::string($row['account_number'] ?? null),
            'iban' => self::iban($row['iban'] ?? null),
            'swift_bic' => self::swiftBic($row['swift_bic'] ?? null),
            'routing_code' => self::routingCode($row['routing_code'] ?? null),
            'duitnow_type' => self::string($row['duitnow_type'] ?? null),
            'duitnow_value' => self::string($row['duitnow_value'] ?? null),
            'ewallet_provider' => self::string($row['ewallet_provider'] ?? null),
            'ewallet_handle' => self::string($row['ewallet_handle'] ?? null),
            'ewallet_qr_payload' => self::string($row['ewallet_qr_payload'] ?? null),
            'reference_note' => self::string($row['reference_note'] ?? null),
            'is_default' => (bool) ($row['is_default'] ?? false),
        ];
    }

    /**
     * Method-specific payout identity. Equal fingerprints mean the same money
     * destination, regardless of formatting; null means the row carries no
     * usable identity.
     *
     * @param  array<string, mixed>  $row
     */
    public static function identityFingerprint(array $row): ?string
    {
        $canonical = self::canonicalRow($row);
        $method = $canonical['method'];

        if (! in_array($method, ['bank_account', 'duitnow', 'ewallet'], true)) {
            return null;
        }

        $parts = match ($method) {
            'bank_account' => [
                $canonical['bank_code'],
                $canonical['account_number'],
                $canonical['iban'],
                $canonical['swift_bic'],
                $canonical['routing_code'],
            ],
            'duitnow' => [$canonical['duitnow_type'], $canonical['duitnow_value']],
            default => [$canonical['ewallet_provider'], $canonical['ewallet_handle'], $canonical['ewallet_qr_payload']],
        };

        $normalized = array_map(
            static fn (?string $value): string => $value === null ? '' : mb_strtolower($value),
            $parts,
        );

        if (array_filter($normalized, static fn (string $value): bool => $value !== '') === []) {
            return null;
        }

        return 'sha256:'.hash('sha256', json_encode([$method, ...$normalized], JSON_THROW_ON_ERROR));
    }

    public static function iban(mixed $value): ?string
    {
        $normalized = self::string($value);

        return $normalized === null ? null : mb_strtoupper((string) preg_replace('/\s+/', '', $normalized));
    }

    public static function swiftBic(mixed $value): ?string
    {
        $normalized = self::string($value);

        return $normalized === null ? null : mb_strtoupper((string) preg_replace('/\s+/', '', $normalized));
    }

    public static function routingCode(mixed $value): ?string
    {
        $normalized = self::string($value);

        return $normalized === null ? null : mb_strtoupper((string) preg_replace('/\s+/', '', $normalized));
    }

    public static function currency(mixed $value): ?string
    {
        $normalized = self::string($value);

        return $normalized === null ? null : mb_strtoupper($normalized);
    }

    public static function isIban(string $iban): bool
    {
        return preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) === 1;
    }

    public static function hasValidIbanChecksum(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;

        foreach (str_split($rearranged) as $character) {
            $digits = ctype_digit($character)
                ? $character
                : (string) (ord($character) - 55);

            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    private static function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
