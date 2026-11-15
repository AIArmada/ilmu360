<?php

declare(strict_types=1);

namespace App\Shared\Media;

/**
 * Immutable reference to a validated temporary upload staged by a transport.
 */
final readonly class StagedUpload
{
    public function __construct(
        public string $path,
        public string $originalFileName,
        public string $mimeType,
        public int $sizeBytes,
    ) {}
}
