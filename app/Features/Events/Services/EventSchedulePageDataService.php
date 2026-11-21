<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Discovery\Api\Orm\PublicScheduleModelLeaf;
use App\Features\Discovery\Api\Orm\PublicScheduleOrmPort;
use App\Features\Events\Api\Orm\EventSchedulePageData;
use App\Features\Events\Models\Builders\EventBuilder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Owner implementation of the upcoming/past event-page schedule split.
 */
final readonly class EventSchedulePageDataService implements EventSchedulePageData
{
    public function __construct(private PublicScheduleOrmPort $schedule) {}

    public function forQuery(
        EventBuilder $baseQuery,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
        ?callable $filterLeaves = null,
    ): array {
        $eventIds = (clone $baseQuery)->reorder()->pluck('events.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        $leaves = $this->schedule->leavesForEventIds($eventIds);

        if ($filterLeaves !== null) {
            /** @var Collection<int, PublicScheduleModelLeaf> $leaves */
            $leaves = $filterLeaves($leaves);
        }

        $now = now();
        $endInstant = static fn (PublicScheduleModelLeaf $leaf): ?CarbonInterface => $leaf->endsAt() ?? $leaf->startsAt();

        $upcomingOverall = $leaves
            ->filter(fn (PublicScheduleModelLeaf $leaf): bool => ($endInstant($leaf)?->greaterThanOrEqualTo($now)) ?? false)
            ->values();

        $upcoming = $upcomingOverall
            ->filter(static function (PublicScheduleModelLeaf $leaf) use ($rangeFrom, $rangeTo): bool {
                if (! $rangeFrom instanceof CarbonInterface || ! $rangeTo instanceof CarbonInterface) {
                    return true;
                }

                $startsAt = $leaf->startsAt();

                return $startsAt instanceof CarbonInterface
                    && $startsAt >= $rangeFrom
                    && $startsAt < $rangeTo;
            })
            ->values();

        $past = $leaves
            ->filter(fn (PublicScheduleModelLeaf $leaf): bool => ($endInstant($leaf)?->lessThan($now)) ?? false)
            ->reverse()
            ->values();

        return [
            'upcoming' => $upcoming->take($upcomingLimit),
            'past' => $past->take($pastLimit),
            'upcoming_total' => $upcoming->count(),
            'past_total' => $past->count(),
            'upcoming_overall_total' => $upcomingOverall->count(),
            'past_overall_total' => $past->count(),
        ];
    }
}
