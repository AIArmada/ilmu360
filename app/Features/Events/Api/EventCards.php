<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Public event-card payload boundary.
 *
 * The Events owner hydrates the event models it owns and returns complete
 * public card payloads, so Discovery never reloads Event models to rehydrate
 * cached search results.
 */
interface EventCards
{
    /**
     * @param  list<string>  $eventIds
     * @return list<array<string, mixed>> Complete card payloads, same shape as
     *                                    {@see \App\Features\Discovery\Api\Data\EventListData::toArray()},
     *                                    in the given id order. Unknown ids are omitted.
     */
    public function forIds(array $eventIds): array;

    /**
     * Full hydrated event arrays (`Event::toArray()` shape) in the given id
     * order, for consumers whose public contract is the raw event payload.
     *
     * @param  list<string>  $eventIds
     * @return list<array<string, mixed>>
     */
    public function fullArraysForIds(array $eventIds): array;
}
