<?php

namespace App\Livewire\Concerns;

use App\Models\Event;
use App\Support\Events\PrimaryOccurrenceSql;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

trait LoadsEventPageData
{
    /**
     * @var array<string, array{upcoming: EloquentCollection<int, Event>, past: EloquentCollection<int, Event>, upcoming_total: int, past_total: int}>
     */
    private array $eventPageDataCache = [];

    /** @return Builder<Event> */
    abstract protected function eventPageBaseQuery(): Builder;

    /**
     * @return array<int, string|array<string, mixed>|\Closure>
     */
    abstract protected function eventPageEagerLoads(): array;

    abstract protected function eventPageUpcomingLimit(): int;

    abstract protected function eventPagePastLimit(): int;

    /**
     * @return array{upcoming: EloquentCollection<int, Event>, past: EloquentCollection<int, Event>, upcoming_total: int, past_total: int}
     */
    protected function eventPageData(): array
    {
        $cacheKey = $this->eventPageUpcomingLimit().':'.$this->eventPagePastLimit();

        if (isset($this->eventPageDataCache[$cacheKey])) {
            return $this->eventPageDataCache[$cacheKey];
        }

        $now = now();
        $eventsTable = (new Event)->getTable();
        $primaryStartsAt = PrimaryOccurrenceSql::column('starts_at', $eventsTable);
        $totals = (clone $this->eventPageBaseQuery())
            ->reorder()
            ->withoutEagerLoads()
            ->selectRaw(
                "SUM(CASE WHEN ({$primaryStartsAt}) >= ? THEN 1 ELSE 0 END) as upcoming_total, SUM(CASE WHEN ({$primaryStartsAt}) < ? THEN 1 ELSE 0 END) as past_total",
                [$now, $now],
            )
            ->first();

        $upcomingTotal = (int) ($totals?->getAttribute('upcoming_total') ?? 0);
        $pastTotal = (int) ($totals?->getAttribute('past_total') ?? 0);

        /** @var EloquentCollection<int, Event> $upcoming */
        $upcoming = $upcomingTotal > 0
            ? $this->eventPageQuery($now, upcoming: true)
                ->orderBy('starts_at')
                ->take($this->eventPageUpcomingLimit())
                ->get()
            : new EloquentCollection;

        /** @var EloquentCollection<int, Event> $past */
        $past = $pastTotal > 0
            ? $this->eventPageQuery($now, upcoming: false)
                ->orderByDesc('starts_at')
                ->take($this->eventPagePastLimit())
                ->get()
            : new EloquentCollection;

        return $this->eventPageDataCache[$cacheKey] = [
            'upcoming' => $upcoming,
            'past' => $past,
            'upcoming_total' => $upcomingTotal,
            'past_total' => $pastTotal,
        ];
    }

    /** @return Builder<Event> */
    protected function eventPageQuery(CarbonInterface $now, bool $upcoming): Builder
    {
        return (clone $this->eventPageBaseQuery())
            ->select('events.*')
            ->where('starts_at', $upcoming ? '>=' : '<', $now)
            ->with($this->eventPageEagerLoads());
    }
}
