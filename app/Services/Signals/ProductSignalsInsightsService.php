<?php

declare(strict_types=1);

namespace App\Services\Signals;

use AIArmada\Signals\Models\SignalEvent;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Support\Timezone\UserDateTimeFormatter;
use Illuminate\Support\Collection;

/**
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
final readonly class ProductSignalsInsightsService
{
    /**
     * @return ProductSignalsDashboard
     */
    public function dashboard(int $days = 30, int $limit = 5000): array
    {
        $events = $this->recentEvents($days, $limit);
        $attributedEvents = $events
            ->filter(fn (SignalEvent $event): bool => $this->nullablePropertyValue($event, 'client_origin') !== null)
            ->values();
        $totalEvents = $attributedEvents->count();

        return [
            'summary' => [
                'events' => $totalEvents,
                'web_events' => $attributedEvents->filter(fn (SignalEvent $event): bool => $this->propertyValue($event, 'client_origin', 'unknown') === 'web')->count(),
                'api_events' => $attributedEvents->filter(fn (SignalEvent $event): bool => $this->propertyValue($event, 'client_transport', 'unknown') === 'api')->count(),
                'mobile_events' => $attributedEvents->filter(fn (SignalEvent $event): bool => $this->propertyValue($event, 'client_family', 'unknown') === 'mobile')->count(),
                'unattributed_events' => $events->count() - $totalEvents,
            ],
            'origin_breakdown' => $this->breakdown($attributedEvents, 'client_origin', $totalEvents),
            'platform_breakdown' => $this->breakdown($attributedEvents, 'client_platform', $totalEvents),
            'transport_breakdown' => $this->breakdown($attributedEvents, 'client_transport', $totalEvents),
            'recent_events' => $attributedEvents
                ->take(25)
                ->map(fn (SignalEvent $event): array => $this->recentEventRow($event))
                ->values()
                ->all(),
            'scorecard' => $this->scorecard($events),
        ];
    }

    /**
     * @param  Collection<int, SignalEvent>  $events
     * @return array{zero_result_filters: list<array{key: string, count: int}>, supply_gaps: list<array{key: string, count: int}>, search_to_outcome: array{searches: int, with_results: int, conversion_rate: float}, moderation_minutes: array{median: float, p90: float}, imminent_pending: int}
     */
    private function scorecard(Collection $events): array
    {
        $discoveryEvents = $events->filter(fn (SignalEvent $event): bool => in_array($event->event_name, ['search.executed', 'listing.filtered'], true));
        $zeroResultEvents = $discoveryEvents->filter(fn (SignalEvent $event): bool => (int) data_get($event->properties, 'result_count', -1) === 0);
        $keyFor = fn (SignalEvent $event): string => data_get($event->properties, 'surface', 'unknown').' · '.implode(',', (array) data_get($event->properties, 'filter_keys', []));
        $zeroResultFilters = $zeroResultEvents->groupBy($keyFor)->map(fn (Collection $group, string $key): array => ['key' => $key, 'count' => $group->count()])->sortByDesc('count')->take(10)->values()->all();

        $searches = $discoveryEvents->count();
        $withResults = $discoveryEvents->filter(fn (SignalEvent $event): bool => (int) data_get($event->properties, 'result_count', 0) > 0)->count();
        $durations = EventSubmission::query()
            ->whereNotNull('submitted_at')
            ->whereHas('event', fn ($query) => $query->whereNotNull('published_at'))
            ->with('event:id,published_at')
            ->get()
            ->map(fn (EventSubmission $submission): ?float => $submission->event?->published_at?->diffInMinutes($submission->submitted_at))
            ->filter(fn (mixed $minutes): bool => is_float($minutes) || is_int($minutes))
            ->map(fn (float|int $minutes): float => (float) $minutes)
            ->sort()
            ->values();

        return [
            'zero_result_filters' => $zeroResultFilters,
            'supply_gaps' => $zeroResultFilters,
            'search_to_outcome' => [
                'searches' => $searches,
                'with_results' => $withResults,
                'conversion_rate' => $searches > 0 ? round(($withResults / $searches) * 100, 1) : 0.0,
            ],
            'moderation_minutes' => [
                'median' => $this->percentile($durations, 0.5),
                'p90' => $this->percentile($durations, 0.9),
            ],
            'imminent_pending' => Event::query()
                ->where('status', 'pending')
                ->whereBetween('starts_at', [now(), now()->addDay()])
                ->count(),
        ];
    }

    /** @param  Collection<int, float>  $values */
    private function percentile(Collection $values, float $percentile): float
    {
        if ($values->isEmpty()) {
            return 0.0;
        }

        $index = (int) ceil($values->count() * $percentile) - 1;

        return round((float) $values->get(max(0, $index)), 1);
    }

    /**
     * @return Collection<int, SignalEvent>
     */
    private function recentEvents(int $days, int $limit): Collection
    {
        return SignalEvent::query()
            ->with('trackedProperty')
            ->where('occurred_at', '>=', now()->subDays($days))
            ->latest('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Collection<int, SignalEvent>  $events
     * @return list<ProductSignalsBreakdownRow>
     */
    private function breakdown(Collection $events, string $property, int $totalEvents): array
    {
        return $events
            ->groupBy(fn (SignalEvent $event): string => $this->propertyValue($event, $property, 'unknown'))
            ->map(fn (Collection $group, string $key): array => [
                'key' => $key,
                'label' => $this->labelForKey($key),
                'count' => $group->count(),
                'share' => $totalEvents > 0 ? round(($group->count() / $totalEvents) * 100, 1) : 0.0,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @return ProductSignalsRecentRow
     */
    private function recentEventRow(SignalEvent $event): array
    {
        return [
            'occurred_at' => UserDateTimeFormatter::format($event->occurred_at, 'M j, Y g:i A'),
            'property' => $event->trackedProperty->name,
            'event_name' => $event->event_name,
            'event_category' => $event->event_category,
            'path' => $event->path,
            'client_origin' => $this->propertyValue($event, 'client_origin', 'unknown'),
            'client_origin_label' => $this->labelForKey($this->propertyValue($event, 'client_origin', 'unknown')),
            'client_platform' => $this->propertyValue($event, 'client_platform', 'unknown'),
            'client_platform_label' => $this->labelForKey($this->propertyValue($event, 'client_platform', 'unknown')),
            'client_family' => $this->propertyValue($event, 'client_family', 'unknown'),
            'client_transport' => $this->propertyValue($event, 'client_transport', 'unknown'),
            'client_transport_label' => $this->labelForKey($this->propertyValue($event, 'client_transport', 'unknown')),
            'client_version' => $this->nullablePropertyValue($event, 'client_version'),
            'query' => $this->nullablePropertyValue($event, 'query'),
        ];
    }

    private function propertyValue(SignalEvent $event, string $key, string $fallback): string
    {
        $value = data_get($event->properties, $key);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private function nullablePropertyValue(SignalEvent $event, string $key): ?string
    {
        $value = data_get($event->properties, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function labelForKey(string $key): string
    {
        return match ($key) {
            'api' => 'API',
            'ios' => 'iOS',
            'ipados' => 'iPadOS',
            'macos' => 'macOS',
            default => str($key)->headline()->toString(),
        };
    }
}
