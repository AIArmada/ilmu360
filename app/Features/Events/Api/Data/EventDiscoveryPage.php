<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

/** Immutable search candidates and pagination metadata; no hydrated models. */
final readonly class EventDiscoveryPage
{
    /** @param list<EventDiscoveryHit> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
        public ?int $from,
        public ?int $to,
        public bool $hasMorePages,
    ) {}

    /** @return list<EventDiscoveryHit> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }
}
