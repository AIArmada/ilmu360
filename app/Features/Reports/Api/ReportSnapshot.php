<?php

declare(strict_types=1);

namespace App\Features\Reports\Api;

use Carbon\CarbonImmutable;

final readonly class ReportSnapshot
{
    public function __construct(
        public string $id,
        public string $entityType,
        public string $entityId,
        public string $category,
        public ?string $description,
        public string $status,
        public ?string $resolutionNote,
        public ?string $reporterId,
        public ?string $handledBy,
        public ?CarbonImmutable $reportedAt,
        public ?CarbonImmutable $createdAt,
        public ?string $entityTitle = null,
    ) {}
}
