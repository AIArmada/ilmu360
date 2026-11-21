<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

final readonly class EventDiscoveryHit
{
    public function __construct(
        public string $id,
        public string $title,
        public ?float $distanceKm,
    ) {}
}
