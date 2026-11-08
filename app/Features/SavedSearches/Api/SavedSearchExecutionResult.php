<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\Api;

final readonly class SavedSearchExecutionResult
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public bool $hasMore,
        public ?int $nextPage,
    ) {}

    /**
     * @return array{page: int, per_page: int, total: int, has_more: bool, next_page: int|null}
     */
    public function pagination(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'has_more' => $this->hasMore,
            'next_page' => $this->nextPage,
        ];
    }
}
