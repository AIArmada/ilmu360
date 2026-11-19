<?php

namespace App\Platform\Ui\Forms;

use App\Platform\Contributions\ContributionPayload;
use App\Shared\Contributions\Support\DonationQrUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class DonationQrFields
{
    /**
     * Project submitted donation rows onto the canonical persisted shape so
     * unchanged channel lists compare equal against the stored state.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeDonationChannelRowsForComparison(array $rows): array
    {
        return ContributionPayload::normalizeDonationChannelRowsForComparison($rows);
    }

    /**
     * Collect donation QR uploads from submitted form state. The field uses
     * `storeFiles(false)`, so submitted values are temporary upload objects
     * — only those are accepted, and string paths are never trusted. Filament
     * stores single uploads under a generated file key
     * (`['<fileKey>' => TemporaryUploadedFile]`), so the first entry wins
     * regardless of numeric or string keys.
     *
     * @return array<int|string, TemporaryUploadedFile>
     */
    public static function collectDonationQrUploads(mixed $donationRows): array
    {
        return DonationQrUploads::collect($donationRows);
    }

    /**
     * Strip QR upload values from dehydrated state so comparisons and stored
     * payloads only see channel data — files travel separately.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function withoutDonationQrUploads(array $state): array
    {
        if (! is_array($state['donation_channels'] ?? null)) {
            return $state;
        }

        foreach ($state['donation_channels'] as $index => $row) {
            if (is_array($row)) {
                unset($state['donation_channels'][$index]['qr_upload']);
            }
        }

        return $state;
    }

    /**
     * Re-key a dehydrated donation array by the raw repeater keys. Filament
     * dehydrates repeater items with `array_values()`, so dehydrated rows are
     * numerically indexed while the rendered fields keep their raw (usually
     * UUID) keys. Mapping back lets error keys point at the rendered field.
     *
     * @param  array<int|string, mixed>  $dehydrated
     * @return array<int|string, mixed>
     */
    public static function rekeyDonationRowsByRawKeys(array $dehydrated, mixed $rawRows): array
    {
        $rawKeys = is_array($rawRows) ? array_keys($rawRows) : [];
        $rekeyed = [];

        foreach (array_values($dehydrated) as $index => $value) {
            $rekeyed[$rawKeys[$index] ?? $index] = $value;
        }

        return $rekeyed;
    }
}
