<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

final readonly class PersonDashboardEvent
{
    public function __construct(
        public string $id,
        public string $title,
        public string $statusLabel,
        public string $scheduleText,
        public ?string $institutionName,
        public int $registrationCount,
        public bool $publiclyReachable,
        public ?string $publicUrl,
        public bool $canEdit,
    ) {}
}
