<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api\Data;

final readonly class EventSearchHitIds
{
    /**
     * @param  list<string>  $eventIds
     * @param  array<string, float>  $distances
     */
    public function __construct(
        public array $eventIds,
        public array $distances = [],
        public int $total = 0,
    ) {}
}
