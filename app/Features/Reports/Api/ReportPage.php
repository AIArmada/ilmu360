<?php

declare(strict_types=1);

namespace App\Features\Reports\Api;

final readonly class ReportPage
{
    /**
     * @param  list<ReportSnapshot>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
    ) {}
}
