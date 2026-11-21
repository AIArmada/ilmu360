<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

use Carbon\CarbonImmutable;

final readonly class EventScheduleCard
{
    public function __construct(
        public string $id,
        public string $url,
        public string $title,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public string $timingText,
        public string $deliveryMode,
        public bool $isPending,
        public ?string $referenceSubtitle,
        public ?string $institutionName,
        public ?string $posterUrl,
        public string $categoryLabel,
        public ?string $location,
    ) {}
}
