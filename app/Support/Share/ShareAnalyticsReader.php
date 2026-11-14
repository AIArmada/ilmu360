<?php

declare(strict_types=1);

namespace App\Support\Share;

use App\Features\ShareTracking\Api\ShareAnalytics;
use Carbon\CarbonImmutable;

final readonly class ShareAnalyticsReader implements ShareAnalytics
{
    public function __construct(
        private ShareMetricReader $metrics,
        private ShareCohortAuthority $cohorts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function memberSummary(
        string $memberId,
        string $timezone,
        CarbonImmutable $fromLocal,
        CarbonImmutable $toLocal,
    ): array {
        return $this->metrics->summarize($timezone, $fromLocal, $toLocal, $memberId);
    }

    /**
     * @return array<string, mixed>
     */
    public function cohortSummary(CarbonImmutable $asOf, int $weeks = 12): array
    {
        return $this->cohorts->summarize($asOf, $weeks);
    }
}
