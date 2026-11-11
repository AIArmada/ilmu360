<?php

declare(strict_types=1);

namespace App\Features\Organizations\Api;

/**
 * Scalar snapshot of one organization for cross-feature readers.
 */
final readonly class OrganizationSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public string $visibility,
        public string $status,
        public int $memberCount,
        public ?string $createdBy = null,
    ) {}
}
