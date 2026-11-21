<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

/** Immutable institution identity and membership-directory presentation. */
final readonly class InstitutionDirectoryRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $slug,
        public ?string $status,
        public string $description,
        public int $memberCount,
    ) {}
}
