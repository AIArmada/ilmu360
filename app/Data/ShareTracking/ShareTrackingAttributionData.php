<?php

declare(strict_types=1);

namespace App\Data\ShareTracking;

use Carbon\CarbonInterface;

final readonly class ShareTrackingAttributionData
{
    public function __construct(
        public string $id,
        public string $backend,
        public string $linkId,
        public ?string $landingUrl,
        public ?string $shareProvider,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $subjectKey,
        public ?string $titleSnapshot,
        public ?CarbonInterface $firstSeenAt,
        public ?CarbonInterface $lastSeenAt,
        public ?CarbonInterface $expiresAt,
    ) {}

}
