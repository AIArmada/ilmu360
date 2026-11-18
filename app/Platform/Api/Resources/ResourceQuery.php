<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

final readonly class ResourceQuery
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        public string $search = '',
        public int $page = 1,
        public int $perPage = 15,
        public array $filters = [],
        public ?string $sort = null,
    ) {}
}
