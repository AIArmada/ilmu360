<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Api\Orm\EventPageScheduleOrmPort;
use App\Features\Events\Models\Event;
use App\Features\Events\Queries\PrimaryOccurrenceSql;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final readonly class EventPageScheduleService implements EventPageScheduleOrmPort
{
    public function forQuery(
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
