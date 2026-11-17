<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Public scalar report response shape for the Events product-signals dashboard.
 *
 * @phpstan-type ProductSignalsSummary array{events: int, web_events: int, api_events: int, mobile_events: int, unattributed_events: int}
 * @phpstan-type ProductSignalsBreakdownRow array{key: string, label: string, count: int, share: float}
 * @phpstan-type ProductSignalsRecentRow array{
 *     occurred_at: string,
 *     property: string,
 *     event_name: string,
 *     event_category: string,
 *     path: string|null,
 *     client_origin: string,
 *     client_origin_label: string,
 *     client_platform: string,
 *     client_platform_label: string,
 *     client_family: string,
 *     client_transport: string,
 *     client_transport_label: string,
 *     client_version: string|null,
 *     query: string|null
 * }
 * @phpstan-type ProductSignalsDashboard array{
 *     summary: ProductSignalsSummary,
 *     origin_breakdown: list<ProductSignalsBreakdownRow>,
 *     platform_breakdown: list<ProductSignalsBreakdownRow>,
 *     transport_breakdown: list<ProductSignalsBreakdownRow>,
 *     recent_events: list<ProductSignalsRecentRow>,
 *     scorecard: array{zero_result_filters: list<array{key: string, count: int}>, supply_gaps: list<array{key: string, count: int}>, search_to_outcome: array{searches: int, with_results: int, conversion_rate: float}, moderation_minutes: array{median: float, p90: float}, imminent_pending: int}
 * }
 */
interface ProductSignalsInsights
{
    /** @return ProductSignalsDashboard */
    public function dashboard(int $days = 30, int $limit = 5000): array;
}
