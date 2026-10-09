<?php

declare(strict_types=1);

namespace App\Data\ShareTracking;

use Carbon\CarbonInterface;

final readonly class ShareTrackingVisitData
{
    public function __construct(
        public string $id,
        public string $backend,
        public string $linkId,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $subjectKey,
        public string $visitKind,
        public ?string $channel,
        public ?CarbonInterface $occurredAt,
    ) {}

}
