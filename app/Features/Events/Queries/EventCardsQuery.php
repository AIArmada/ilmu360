<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use App\Features\Events\Queries\EventListPayloadPresenter;
use App\Features\Events\Api\EventCards;
use App\Features\Events\Models\Event;
use Illuminate\Support\Collection;

final readonly class EventCardsQuery implements EventCards
{
    public function __construct(
        private EventSearchRelationProvider $relationProvider,
        private EventListPayloadPresenter $eventListPayloads,
    ) {}

    /**
     * @param  list<string>  $eventIds
     * @return list<array<string, mixed>>
     */
    public function forIds(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $events = Event::query()
            ->with($this->relationProvider->relations())
            ->whereHas('occurrences')
            ->whereKey($eventIds)
            ->get()
            ->keyBy('id');

        return $this->orderedEvents($eventIds, $events)
            ->map(fn (Event $event): array => $this->eventListPayloads->forEvent($event)->toArray())
            ->all();
    }

    /**
     * @param  list<string>  $eventIds
     * @return list<array<string, mixed>>
     */
    public function fullArraysForIds(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $events = Event::query()
            ->with($this->relationProvider->relations())
            ->whereHas('occurrences')
            ->whereKey($eventIds)
            ->get()
            ->keyBy('id');

        return $this->orderedEvents($eventIds, $events)
            ->map(static fn (Event $event): array => $event->toArray())
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $eventIds
     * @param  Collection<string, Event>  $events
     * @return Collection<int, Event>
     */
    private function orderedEvents(array $eventIds, Collection $events): Collection
    {
        return collect($eventIds)
            ->map(static fn (string $eventId): ?Event => $events->get($eventId))
            ->filter()
            ->values();
    }
}
