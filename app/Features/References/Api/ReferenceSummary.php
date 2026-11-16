<?php

declare(strict_types=1);

namespace App\Features\References\Api;

/** Immutable public and membership display snapshot. */
final readonly class ReferenceSummary
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $slug,
        public ?string $status,
        public ?string $publicUrl,
        public ?string $adminUrl,
        public ?string $imageUrl,
    ) {}
}
