<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api\Data;

/**
 * Immutable page of public event-search results.
 *
 * The page carries the already-serialized event cards plus the pagination
 * metadata from the underlying Typesense/database pipeline, so callers never
 * receive mutable Eloquent models or paginators across the feature boundary.
 */
final readonly class EventSearchPage
{
    /**
     * @param  list<EventListData>  $items
     * @param  array<string, float>  $distances  Event id => rounded distance in kilometres.
     */
    public function __construct(
        public array $items,
        public array $distances,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
        public ?int $from,
        public ?int $to,
        public bool $hasMorePages,
    ) {}

    /**
     * @return list<EventListData>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }
}
