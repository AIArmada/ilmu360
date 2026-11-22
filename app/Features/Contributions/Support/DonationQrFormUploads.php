<?php

declare(strict_types=1);

namespace App\Features\Contributions\Support;

use App\Features\DonationChannels\Api\Data\DonationQrUploadData;
use App\Features\DonationChannels\Api\DonationQrWorkflow;
use App\Platform\Ui\Forms\DonationQrUploads;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** Extract temporary Livewire files before entering the DonationChannels boundary. */
final class DonationQrFormUploads
{
    public static function assertFresh(mixed $donationRows): void
    {
        $rows = is_array($donationRows) ? $donationRows : [];
        $failed = [];

        foreach (DonationQrUploads::collect($rows) as $index => $file) {
            $row = $rows[$index] ?? null;
            $fingerprint = is_array($row) ? app(DonationQrWorkflow::class)->donationChannelFingerprint($row) : null;

            try {
                $fresh = $file->exists();
            } catch (\Throwable) {
                $fresh = false;
            }

            if (! $fresh || $fingerprint === null) {
                $failed["data.donation_channels.{$index}.qr_upload"] = __('The QR upload expired or is invalid. Please upload the QR image again.');
            }
        }

        if ($failed !== []) {
            throw ValidationException::withMessages($failed);
        }
    }

    /** @param array<int|string, TemporaryUploadedFile> $uploads
     *  @return array<int|string, DonationQrUploadData>
     */
    public static function prepare(array $uploads): array
    {
        $prepared = [];

        foreach ($uploads as $index => $file) {
            try {
                $contents = $file->exists() ? $file->get() : null;
            } catch (\Throwable) {
                $contents = null;
            }

            $prepared[$index] = new DonationQrUploadData(
                contents: is_string($contents) ? $contents : null,
                guessedExtension: (string) $file->guessExtension(),
                originalFileName: $file->getClientOriginalName(),
            );
        }

        return $prepared;
    }
}
