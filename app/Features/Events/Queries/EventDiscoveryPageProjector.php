<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\Events\Api\Data\EventDiscoveryHit;
use App\Features\Events\Api\Data\EventDiscoveryPage;
use App\Features\Events\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EventDiscoveryPageProjector
{
    /** @param LengthAwarePaginator<int, Event> $paginator */
    public static function fromPaginator(LengthAwarePaginator $paginator): EventDiscoveryPage
    {
        $items = array_map(static function (Event $event): EventDiscoveryHit {
            $distance = $event->getAttributes()['distance_km'] ?? null;

            return new EventDiscoveryHit(
                id: (string) $event->getKey(),
                title: (string) $event->title,
                distanceKm: is_numeric($distance) ? (float) $distance : null,
            );
        }, $paginator->items());

        return new EventDiscoveryPage(
            items: $items,
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            from: $paginator->firstItem(),
            to: $paginator->lastItem(),
            hasMorePages: $paginator->hasMorePages(),
        );
    }
}
