<?php

declare(strict_types=1);

namespace App\Data\ShareTracking;

use Carbon\CarbonInterface;

final readonly class ShareTrackingLinkData
{
    public function __construct(
        public string $id,
        public string $backend,
        public string $subjectType,
        public ?string $subjectId,
        public string $subjectKey,
        public string $destinationUrl,
        public string $canonicalUrl,
        public string $titleSnapshot,
        public ?CarbonInterface $lastSharedAt,
        public int $outboundShares = 0,
        public int $visitsCount = 0,
        public int $outcomesCount = 0,
        public int $signupsCount = 0,
        public int $eventRegistrationsCount = 0,
        public int $eventCheckinsCount = 0,
        public int $eventSubmissionsCount = 0,
        public ?CarbonInterface $latestVisitAt = null,
        public ?CarbonInterface $latestOutcomeAt = null,
    ) {}

    public function latestActivityAt(): ?CarbonInterface
    {
        return collect([
            $this->lastSharedAt,
            $this->latestVisitAt,
            $this->latestOutcomeAt,
        ])->filter()->sortByDesc(fn (CarbonInterface $value): int => $value->getTimestamp())->first();
    }
}
