<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use AIArmada\Events\Models\EventOccurrence;
use App\Features\Events\Api\EventCards;
use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
            ->get();

        $events->loadMissing(['displayOccurrence', 'primaryOccurrence']);

        $occurrences = $events
            ->flatMap(static fn (Event $event): array => array_values(array_filter([
                $event->displayOccurrence,
                $event->primaryOccurrence,
            ], static fn (mixed $occurrence): bool => $occurrence instanceof EventOccurrence)))
            ->unique(static fn (EventOccurrence $occurrence): string => (string) $occurrence->getKey())
            ->values();

        if ($occurrences->isNotEmpty()) {
            new EloquentCollection($occurrences->all())->loadMissing('media');
        }

        return $this->orderedEvents($eventIds, $events->keyBy('id'))
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
            ->with([...$this->relationProvider->relations(), 'displayOccurrence.media'])
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
