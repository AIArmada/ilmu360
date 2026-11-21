<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Api\Orm\EventPageScheduleOrmPort;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Events\Queries\PrimaryOccurrenceSql;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final readonly class EventPageScheduleService implements EventPageScheduleOrmPort
{
    public function forVenue(
        string $venueId,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
    ): array {
        return $this->forQuery(
            Event::query()->where('default_venue_id', $venueId)->active(),
            [
                'institution.media',
                'institution.addresses.country',
                'persons.media',
                'keyPeople.person.media',
                'media',
            ],
            $upcomingLimit,
            $pastLimit,
            $rangeFrom,
            $rangeTo,
        );
    }

    public function forSeries(
        string $seriesId,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
    ): array {
        $series = Series::query()->find($seriesId);

        if (! $series instanceof Series) {
            return $this->emptyPageData();
        }

        return $this->forQuery(
            $series->events()->getQuery()->active(),
            [
                'references' => fn ($query) => $query->active(),
                'institution',
                'institution.addresses.country',
                'venue.addresses.country',
                'media',
            ],
            $upcomingLimit,
            $pastLimit,
            $rangeFrom,
            $rangeTo,
        );
    }

    /**
     * @param  Builder<Event>  $baseQuery
     * @param  array<int|string, string|array<string, mixed>|\Closure>  $eagerLoads
     * @return array{upcoming: EloquentCollection<int, Event>, past: EloquentCollection<int, Event>, upcoming_total: int, past_total: int}
     */
    private function forQuery(
        Builder $baseQuery,
        array $eagerLoads,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
    ): array {
        $now = now();
        $eventsTable = (new Event)->getTable();
        $primaryStartsAt = PrimaryOccurrenceSql::column('starts_at', $eventsTable);
        $primaryEndsAt = PrimaryOccurrenceSql::endInstant($eventsTable);
        $hasRange = $rangeFrom instanceof CarbonInterface && $rangeTo instanceof CarbonInterface;
        $upcomingClause = $hasRange
            ? "({$primaryEndsAt}) >= ? AND ({$primaryStartsAt}) >= ? AND ({$primaryStartsAt}) < ?"
            : "({$primaryEndsAt}) >= ?";
        $bindings = $hasRange
            ? [$now, $rangeFrom, $rangeTo, $now]
            : [$now, $now];

        $totals = (clone $baseQuery)
            ->reorder()
            ->withoutEagerLoads()
            ->selectRaw(
                "SUM(CASE WHEN {$upcomingClause} THEN 1 ELSE 0 END) as upcoming_total, SUM(CASE WHEN ({$primaryEndsAt}) < ? THEN 1 ELSE 0 END) as past_total",
                $bindings,
            )
            ->first();

        $upcomingTotal = (int) ($totals?->getAttribute('upcoming_total') ?? 0);
        $pastTotal = (int) ($totals?->getAttribute('past_total') ?? 0);

        /** @var EloquentCollection<int, Event> $upcoming */
        $upcoming = $upcomingTotal > 0
            ? $this->query($baseQuery, $eagerLoads, $primaryEndsAt, $now, upcoming: true)
                ->when($hasRange, function (Builder $query) use ($rangeFrom, $rangeTo): void {
                    $query->where('starts_at', '>=', $rangeFrom)
                        ->where('starts_at', '<', $rangeTo);
                })
                ->orderBy('starts_at')
                ->take($upcomingLimit)
                ->get()
            : new EloquentCollection;

        /** @var EloquentCollection<int, Event> $past */
        $past = $pastTotal > 0
            ? $this->query($baseQuery, $eagerLoads, $primaryEndsAt, $now, upcoming: false)
                ->orderByDesc('starts_at')
                ->take($pastLimit)
                ->get()
            : new EloquentCollection;

        return [
            'upcoming' => $upcoming,
            'past' => $past,
            'upcoming_total' => $upcomingTotal,
            'past_total' => $pastTotal,
        ];
    }

    /** @return array{upcoming: EloquentCollection<int, Event>, past: EloquentCollection<int, Event>, upcoming_total: int, past_total: int} */
    private function emptyPageData(): array
    {
        return [
            'upcoming' => new EloquentCollection,
            'past' => new EloquentCollection,
            'upcoming_total' => 0,
            'past_total' => 0,
        ];
    }

    /**
     * @param  Builder<Event>  $baseQuery
     * @param  array<int|string, string|array<string, mixed>|\Closure>  $eagerLoads
     * @return Builder<Event>
     */
    private function query(Builder $baseQuery, array $eagerLoads, string $endInstant, CarbonInterface $now, bool $upcoming): Builder
    {
        return (clone $baseQuery)
            ->select('events.*')
            ->whereRaw("{$endInstant} ".($upcoming ? '>=' : '<').' ?', [$now])
            ->with($eagerLoads);
    }
}
