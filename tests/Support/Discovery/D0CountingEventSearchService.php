<?php

declare(strict_types=1);

namespace Tests\Support\Discovery;

use App\Features\Discovery\Api\Data\EventSearchPage;
use App\Features\Discovery\Services\EventSearchService;
use App\Features\Events\Models\Event;
use App\Features\Events\Queries\EventListPayloadPresenter;

/**
 * D0 characterization: EventNotificationService::savedSearchMatchesEvent()
 * currently performs one full EventSearchService query per saved-search
 * candidate, then checks whether the candidate event appears in the first 50
 * results. This records that N candidates cost N full searches.
 *
 * The targeted matcher belongs to D5; D0 must not change the search path.
 */
final class D0CountingEventSearchService extends EventSearchService
{
    public int $searchCalls = 0;

    public int $nearbyCalls = 0;

    /** @var list<array{query: ?string, filters: array<string, mixed>, perPage: int}> */
    public array $searchArguments = [];

    /** @var list<Event> */
    public array $results = [];

    public function __construct() {}

    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 20,
        string $sort = 'time'
    ): EventSearchPage {
        $this->searchCalls++;
        $this->searchArguments[] = [
            'query' => $query,
            'filters' => $filters,
            'perPage' => $perPage,
        ];

        return $this->page($perPage);
    }

    public function searchNearby(
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): EventSearchPage {
        $this->nearbyCalls++;

        return $this->page($perPage);
    }

    private function page(int $perPage): EventSearchPage
    {
        $items = array_map(
            static fn (Event $event) => app(EventListPayloadPresenter::class)->forEvent($event),
            $this->results,
        );
        $total = count($items);

        return new EventSearchPage(
            items: $items,
            distances: [],
            total: $total,
            perPage: $perPage,
            currentPage: 1,
            lastPage: 1,
            from: $total > 0 ? 1 : null,
            to: $total > 0 ? $total : null,
            hasMorePages: false,
        );
    }
}
