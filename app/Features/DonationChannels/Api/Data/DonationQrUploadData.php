<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api\Data;

/** Uploaded QR contents and MIME-derived extension captured by a form adapter. */
final readonly class DonationQrUploadData
{
    public function __construct(
        public ?string $contents,
        public string $guessedExtension,
        public string $originalFileName,
    ) {}
}
