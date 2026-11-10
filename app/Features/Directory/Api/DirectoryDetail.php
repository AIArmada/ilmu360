<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Public display snapshot for one Directory record.
 *
 * Values only: no Eloquent model, builder or media relation crosses the
 * Directory boundary.
 */
final readonly class DirectoryDetail
{
    public function __construct(
        public DirectoryRecordType $type,
        public string $id,
        public string $title,
        public ?string $slug,
        public ?string $status,
        public ?string $publicUrl,
        public ?string $adminUrl,
        public ?string $imageUrl,
    ) {}
}
