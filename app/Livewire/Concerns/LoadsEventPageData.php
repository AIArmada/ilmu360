<?php

namespace App\Livewire\Concerns;

use App\Models\Event;
use App\Support\Events\PrimaryOccurrenceSql;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

trait LoadsEventPageData
{
    /**
     * @var array<string, array{upcoming: EloquentCollection<int, Event>, past: EloquentCollection<int, Event>, upcoming_total: int, past_total: int}>
     */
    private array $eventPageDataCache = [];

    public string $upcomingDateFilter = 'all';

    public string $customStartDate = '';

    public string $customEndDate = '';

    public bool $showCustomDateRange = false;

    /** @return Builder<Event> */
    abstract protected function eventPageBaseQuery(): Builder;

    /**
     * @return array<int|string, string|array<string, mixed>|\Closure>
     */
    abstract protected function eventPageEagerLoads(): array;

    abstract protected function eventPageUpcomingLimit(): int;

    abstract protected function eventPagePastLimit(): int;

    public function filterUpcomingEvents(): void
    {
        $this->eventPageDataCache = [];
    }

    public function updatedUpcomingDateFilter(): void
    {
        if ($this->upcomingDateFilter === 'custom') {
            $this->resetValidation('customDateRange');

            return;
        }

        $this->filterUpcomingEvents();
    }

    public function applyCustomDateRange(): void
    {
        $from = UserDateTimeFormatter::parseUserDateToUtc($this->customStartDate);
        $to = UserDateTimeFormatter::parseUserDateToUtc($this->customEndDate);

        if ($from === null || $to === null || $from->greaterThan($to)) {
            $this->addError('customDateRange', __('Sila pilih julat tarikh yang sah.'));

            return;
        }

        $this->resetValidation('customDateRange');
        $this->upcomingDateFilter = 'custom';
        $this->showCustomDateRange = false;
        $this->filterUpcomingEvents();
    }

    public function clearUpcomingDateFilter(): void
    {
        $this->upcomingDateFilter = 'all';
        $this->customStartDate = '';
        $this->customEndDate = '';
        $this->showCustomDateRange = false;
        $this->resetValidation('customDateRange');
        $this->filterUpcomingEvents();
    }

    /**
     * @return array{from: CarbonInterface, to: CarbonInterface}|null
     */
    protected function upcomingDateRange(): ?array
    {
        if ($this->upcomingDateFilter === 'custom') {
            $from = UserDateTimeFormatter::parseUserDateToUtc($this->customStartDate);
            $to = UserDateTimeFormatter::parseUserDateToUtc($this->customEndDate)?->addDay();

            if ($from === null || $to === null || $from->greaterThanOrEqualTo($to)) {
                return null;
            }

            return [
                'from' => $from,
                'to' => $to,
            ];
        }

        $today = UserDateTimeFormatter::userNow()->startOfDay();

        [$from, $to] = match ($this->upcomingDateFilter) {
            'today' => [$today, $today->copy()->addDay()],
            'tomorrow' => [$today->copy()->addDay(), $today->copy()->addDays(2)],
            'this_week' => [$today, $today->copy()->startOfWeek()->addWeek()],
            'this_weekend' => $this->weekendDateRange($today),
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addMonth()],
            'next_week' => [
                $today->copy()->startOfWeek()->addWeek(),
                $today->copy()->startOfWeek()->addWeeks(2),
            ],
            'next_month' => [
                $today->copy()->startOfMonth()->addMonth(),
                $today->copy()->startOfMonth()->addMonths(2),
            ],
            default => [null, null],
        };

        if ($from === null || $to === null) {
            return null;
        }

        return [
            'from' => $from->utc(),
            'to' => $to->utc(),
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function weekendDateRange(CarbonInterface $today): array
    {
        $weekendStart = $today->isSaturday() || $today->isSunday()
            ? $today->copy()
            : $today->copy()->next(Carbon::SATURDAY);

        return [$weekendStart, $weekendStart->copy()->addDays(2)->startOfDay()];
    }

    /**
     * @return array{upcoming: EloquentCollection<int, Event>, past: EloquentCollection<int, Event>, upcoming_total: int, past_total: int}
     */
    protected function eventPageData(): array
    {
        $cacheKey = $this->eventPageUpcomingLimit().':'.$this->eventPagePastLimit();

        if (isset($this->eventPageDataCache[$cacheKey])) {
            return $this->eventPageDataCache[$cacheKey];
        }

        $range = $this->upcomingDateRange();
        $now = now();
        $eventsTable = (new Event)->getTable();
        $primaryStartsAt = PrimaryOccurrenceSql::column('starts_at', $eventsTable);
        $upcomingClause = $range === null
            ? "({$primaryStartsAt}) >= ?"
            : "({$primaryStartsAt}) >= ? AND ({$primaryStartsAt}) < ?";
        $totals = (clone $this->eventPageBaseQuery())
            ->reorder()
            ->withoutEagerLoads()
            ->selectRaw(
                "SUM(CASE WHEN {$upcomingClause} THEN 1 ELSE 0 END) as upcoming_total, SUM(CASE WHEN ({$primaryStartsAt}) < ? THEN 1 ELSE 0 END) as past_total",
                $range === null ? [$now, $now] : [$range['from'], $range['to'], $now],
            )
            ->first();

        $upcomingTotal = (int) ($totals?->getAttribute('upcoming_total') ?? 0);
        $pastTotal = (int) ($totals?->getAttribute('past_total') ?? 0);

        /** @var EloquentCollection<int, Event> $upcoming */
        $upcoming = $upcomingTotal > 0
            ? $this->eventPageQuery($now, upcoming: true)
                ->when($range !== null, function (Builder $query) use ($range): void {
                    $query->where('starts_at', '>=', $range['from'])
                        ->where('starts_at', '<', $range['to']);
                })
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
