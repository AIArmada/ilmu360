<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

final readonly class PersonDashboardEventPage
{
    /** @param list<PersonDashboardEvent> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
    ) {}
}
