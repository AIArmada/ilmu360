<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

final readonly class InstitutionDashboardEventCard
{
    public function __construct(
        public string $id,
        public string $title,
        public string $status,
        public string $publicUrl,
        public string $cardImageUrl,
        public string $dateDay,
        public string $dateMonth,
        public string $dateWeekday,
        public string $timeLabel,
        public string $locationLabel,
        public ?string $editUrl,
    ) {}
}
