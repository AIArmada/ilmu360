<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

use Carbon\CarbonImmutable;

interface ShareAnalytics
{
    /**
     * @return array<string, mixed>
     */
    public function memberSummary(
        string $memberId,
        string $timezone,
        CarbonImmutable $fromLocal,
        CarbonImmutable $toLocal,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function cohortSummary(CarbonImmutable $asOf, int $weeks = 12): array;
}
