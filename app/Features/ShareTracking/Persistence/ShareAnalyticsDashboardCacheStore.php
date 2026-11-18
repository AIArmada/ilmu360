<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Persistence;

use App\Features\ShareTracking\Services\Analytics\AdminShareAnalyticsService;
use App\Shared\ShareTracking\ShareTrackingMeasurement;
use Illuminate\Support\Facades\Cache;

final readonly class ShareAnalyticsDashboardCacheStore
{
    /**
     * @return array<string, mixed>
     */
    public function get(AdminShareAnalyticsService $analytics): array
    {
        return Cache::remember(
            'filament.share-analytics.dashboard:'.app()->getLocale().':'.(ShareTrackingMeasurement::reportingStart() ?? 'unconfigured').':'.ShareTrackingMeasurement::definitionVersion(),
            now()->addMinute(),
            fn (): array => $analytics->dashboard(),
        );
    }
}
