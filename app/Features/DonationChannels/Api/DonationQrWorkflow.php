<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api;

use App\Features\DonationChannels\Api\Data\DonationQrUploadData;

/** QR media operations with scalar subject identities and immutable upload data. */
interface DonationQrWorkflow
{
    /** @param array<string, mixed> $row */
    public function donationChannelFingerprint(array $row): ?string;

    public function safeDonationQrFileName(string $guessedExtension, string $baseName = 'media'): string;

    /** @param array<int|string, array<string, mixed>> $submittedRows
     *  @param array<int|string, DonationQrUploadData> $uploads
     */
    public function attachDonationQrUploads(string $subjectType, string $subjectId, array $submittedRows, array $uploads): void;

    public function attachStagedDonationQr(string $subjectType, string $subjectId, string $stagedMediaId): void;
}
