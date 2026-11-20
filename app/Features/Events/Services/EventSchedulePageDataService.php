<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Discovery\Api\PublicScheduleLeaf;
use App\Features\Discovery\Api\PublicScheduleReader;
use App\Features\Events\Api\EventSchedulePageData;
use App\Features\Events\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Owner implementation of the upcoming/past event-page schedule split.
 */
final readonly class EventSchedulePageDataService implements EventSchedulePageData
{
    public function __construct(private PublicScheduleReader $schedule) {}

    public function forQuery(
        object $baseQuery,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
        ?callable $filterLeaves = null,
    ): array {
        if (! $baseQuery instanceof Builder) {
            throw new InvalidArgumentException('The event-page base query must be an Eloquent builder.');
        }

        $events = (clone $baseQuery)->reorder()->select('events.*')->get()
            ->filter(static fn (Model $event): bool => $event instanceof Event)
            ->values();
        $leaves = $this->schedule->leavesForEvents($events);

        if ($filterLeaves !== null) {
            /** @var Collection<int, PublicScheduleLeaf> $leaves */
            $leaves = $filterLeaves($leaves);
        }

        $now = now();
        $endInstant = static fn (PublicScheduleLeaf $leaf): ?CarbonInterface => $leaf->endsAt() ?? $leaf->startsAt();

        $upcomingOverall = $leaves
            ->filter(fn (PublicScheduleLeaf $leaf): bool => ($endInstant($leaf)?->greaterThanOrEqualTo($now)) ?? false)
            ->values();

        $upcoming = $upcomingOverall
            ->filter(static function (PublicScheduleLeaf $leaf) use ($rangeFrom, $rangeTo): bool {
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
            ->filter(fn (PublicScheduleLeaf $leaf): bool => ($endInstant($leaf)?->lessThan($now)) ?? false)
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
