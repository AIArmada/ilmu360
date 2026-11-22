<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

use Carbon\CarbonImmutable;

/** Immutable planner card from the owning Events read model. */
final readonly class PlannerEventCard
{
    public function __construct(
        public string $id,
        public string $title,
        public string $status,
        public ?CarbonImmutable $startsAt,
        public string $url,
        public string $imageUrl,
        public ?string $locationName,
    ) {}
}
