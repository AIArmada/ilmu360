<?php

declare(strict_types=1);

use App\Features\Communications\Api\NotificationCadence;
use App\Features\Communications\Api\NotificationDispatchData;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\Discovery\Api\Data\EventSearchPage;
use App\Features\Discovery\Api\EventListPayloads;
use App\Features\Discovery\Services\EventSearchService;
use App\Features\Events\Models\Event;
use App\Features\Events\Notifications\EventNotificationService;
use App\Features\Institutions\Models\Institution;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\User;

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
            static fn (Event $event) => app(EventListPayloads::class)->forEvent($event),
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

final class D0SavedSearchNotificationRecorder extends EventNotificationService
{
    /** @var list<NotificationDispatchData> */
    public array $recorded = [];

    protected function dispatchForUsers(iterable $users, callable $builder): void
    {
        foreach ($users as $user) {
            $this->recorded[] = $builder($user);
        }
    }
}

it('runs one full search per saved-search candidate and groups matching candidates', function (): void {
    config(['scout.driver' => 'database']);
    $this->travelTo('2026-04-01 00:00:00');

    $owner = User::factory()->create();
    $event = Event::factory()->for(Institution::factory())->create([
        'title' => 'Kuliah Tafsir D0',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(2),
    ]);

    $textSearchOne = SavedSearch::factory()->create([
        'user_id' => $owner->id,
        'name' => 'D0 text one',
        'query' => 'kuliah tafsir',
        'filters' => [],
        'lat' => null,
        'lng' => null,
        'radius_km' => null,
        'notify' => 'instant',
    ]);
    $textSearchTwo = SavedSearch::factory()->create([
        'user_id' => $owner->id,
        'name' => 'D0 text two',
        'query' => 'tauhid',
        'filters' => [],
        'lat' => null,
        'lng' => null,
        'radius_km' => null,
        'notify' => 'weekly',
    ]);
    $nearbySearch = SavedSearch::factory()->create([
        'user_id' => $owner->id,
        'name' => 'D0 nearby',
        'query' => null,
        'filters' => [],
        'lat' => 3.1390,
        'lng' => 101.6869,
        'radius_km' => 12,
        'notify' => 'daily',
    ]);

    $fake = new D0CountingEventSearchService;
    $fake->results = [$event];
    $this->app->instance(EventSearchService::class, $fake);

    $service = app(D0SavedSearchNotificationRecorder::class);
    $service->notifyPublication($event);

    expect($fake->searchCalls)->toBe(2)
        ->and($fake->nearbyCalls)->toBe(1)
        ->and(collect($fake->searchArguments)->pluck('perPage')->unique()->all())->toBe([50])
        ->and(collect($fake->searchArguments)->pluck('query')->all())->toEqualCanonicalizing(['kuliah tafsir', 'tauhid'])
        ->and($service->recorded)->toHaveCount(1);
    $notification = $service->recorded[0];

    expect($notification->trigger)->toBe(NotificationTrigger::SavedSearchMatch)
        ->and($notification->forcedCadence)->toBe(NotificationCadence::Instant)
        ->and($notification->meta['saved_search_ids'])->toEqualCanonicalizing([
            $textSearchOne->id,
            $textSearchTwo->id,
            $nearbySearch->id,
        ]);
});
