<?php

declare(strict_types=1);

namespace App\Shared\Contributions\Support;

use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Collects donation QR uploads from submitted contribution form state.
 *
 * The QR field uses `storeFiles(false)`, so submitted values are temporary
 * upload objects — only those are accepted, and string paths are never
 * trusted. Filament stores single uploads under a generated file key
 * (`['<fileKey>' => TemporaryUploadedFile]`), so the first entry wins
 * regardless of numeric or string keys.
 */
final class DonationQrUploads
{
    /**
     * @return array<int|string, TemporaryUploadedFile>
     */
    public static function collect(mixed $donationRows): array
    {
        $uploads = [];

        if (! is_array($donationRows)) {
            return $uploads;
        }

        foreach ($donationRows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $file = $row['qr_upload'] ?? null;
            $file = is_array($file) ? Arr::first($file) : $file;

            if ($file instanceof TemporaryUploadedFile) {
                $uploads[$index] = $file;
            }
        }

        return $uploads;
    }
}
