<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

use Carbon\CarbonImmutable;

/** Immutable Events-owned administrative account card. */
final readonly class AdminEventCard
{
    public function __construct(
        public string $id,
        public string $title,
        public string $status,
        public ?CarbonImmutable $startsAt,
        public ?string $institutionName,
        public ?string $venueName,
    ) {}
}
