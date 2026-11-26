<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Addressing\Features\AddressLifecycle\Support\AddressHierarchyFormatter;
use App\Features\Events\Api\Data\EventScheduleCard;
use App\Features\Events\Api\EventPageSchedule;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Events\Queries\EventCategoryPresenter;
use App\Features\Events\Queries\PrimaryOccurrenceSql;
use App\Features\Events\States\EventStatus\Pending;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final readonly class EventPageScheduleService implements EventPageSchedule
{
    public function __construct(private EventCategoryPresenter $categories) {}

    public function forVenue(string $venueId, int $upcomingLimit, int $pastLimit): array
    {
        return $this->forQuery(
            Event::query()->where('default_venue_id', $venueId)->active(),
            [
                'classifications.term',
                'institution.media',
                'institution.addresses.country',
                'persons.media',
                'keyPeople.person.media',
                'media',
            ],
            $upcomingLimit,
            $pastLimit,
        );
    }

    public function forSeries(string $seriesId, int $upcomingLimit, int $pastLimit): array
    {
        $series = Series::query()->find($seriesId);

        if (! $series instanceof Series) {
            return $this->emptyPageData();
        }

        return $this->forQuery(
            $series->events()->getQuery()->active(),
            [
                'classifications.term',
                'references' => fn ($query) => $query->active(),
                'institution',
                'institution.addresses.country',
                'venue.addresses.country',
                'media',
            ],
            $upcomingLimit,
            $pastLimit,
        );
    }

    /**
     * @param  Builder<Event>  $baseQuery
     * @param  array<int|string, string|array<string, mixed>|\Closure>  $eagerLoads
     * @return array{upcoming: list<EventScheduleCard>, past: list<EventScheduleCard>, upcoming_total: int, past_total: int}
     */
    private function forQuery(Builder $baseQuery, array $eagerLoads, int $upcomingLimit, int $pastLimit): array
    {
        $now = now();
        $table = (new Event)->getTable();
        $primaryEndsAt = PrimaryOccurrenceSql::endInstant($table);

        $totals = (clone $baseQuery)
            ->reorder()
            ->withoutEagerLoads()
            ->selectRaw(
                "SUM(CASE WHEN ({$primaryEndsAt}) >= ? THEN 1 ELSE 0 END) as upcoming_total, SUM(CASE WHEN ({$primaryEndsAt}) < ? THEN 1 ELSE 0 END) as past_total",
                [$now, $now],
            )
            ->first();

        $upcomingTotal = (int) ($totals?->getAttribute('upcoming_total') ?? 0);
        $pastTotal = (int) ($totals?->getAttribute('past_total') ?? 0);

        /** @var EloquentCollection<int, Event> $upcoming */
        $upcoming = $upcomingTotal > 0
            ? $this->query($baseQuery, $eagerLoads, $primaryEndsAt, $now, upcoming: true)
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
            'upcoming' => $upcoming->map($this->card(...))->values()->all(),
            'past' => $past->map($this->card(...))->values()->all(),
            'upcoming_total' => $upcomingTotal,
            'past_total' => $pastTotal,
        ];
    }

    /**
     * @return array{upcoming: list<EventScheduleCard>, past: list<EventScheduleCard>, upcoming_total: int, past_total: int}
     */
    private function emptyPageData(): array
    {
        return [
            'upcoming' => [],
            'past' => [],
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

    private function card(Event $event): EventScheduleCard
    {
        $parts = array_values(array_filter([
            $event->resolvedLocationName(),
            ...AddressHierarchyFormatter::parts($event->resolvedLocationAddress()),
        ], static fn (mixed $part): bool => is_string($part) && $part !== ''));

        $posterUrl = $event->getFirstMediaUrl('poster', 'thumb')
            ?: $event->institution?->getFirstMediaUrl('logo', 'thumb');

        $start = $event->starts_at;
        $end = $event->ends_at;
        $format = $event->delivery_mode;

        return new EventScheduleCard(
            id: (string) $event->getKey(),
            url: route('events.show', $event),
            title: (string) $event->title,
            startsAt: $start instanceof CarbonInterface ? CarbonImmutable::instance($start) : null,
            endsAt: $end instanceof CarbonInterface ? CarbonImmutable::instance($end) : null,
            timingText: $event->timing_display !== ''
                ? (string) $event->timing_display
                : \AIArmada\CommerceSupport\Support\UserDateTimeFormatter::format($start, 'h:i A'),
            deliveryMode: $format instanceof \BackedEnum ? (string) $format->value : (string) $format,
            isPending: $event->status instanceof Pending,
            referenceSubtitle: $event->reference_study_subtitle,
            institutionName: $event->institution?->name,
            posterUrl: $posterUrl ?: null,
            categoryLabel: $this->categories->forEvent($event)[0]['path'] ?? (string) __('Umum'),
            location: $parts !== [] ? implode(', ', $parts) : null,
        );
    }
}
