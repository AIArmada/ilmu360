<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

/** Immutable speaker identity and membership-directory presentation. */
final readonly class PersonDirectoryRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public string $formattedName,
        public ?string $slug,
        public ?string $status,
        public string $bio,
        public int $memberCount,
    ) {}
}
