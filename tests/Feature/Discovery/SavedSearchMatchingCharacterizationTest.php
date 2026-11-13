<?php

declare(strict_types=1);

use App\Features\Communications\Api\NotificationCadence;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Services\EventSearchService;
use App\Services\Notifications\EventNotificationService;
use App\Support\Notifications\NotificationDispatchData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

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
    ): LengthAwarePaginator {
        $this->searchCalls++;
        $this->searchArguments[] = [
            'query' => $query,
            'filters' => $filters,
            'perPage' => $perPage,
        ];

        return new Paginator($this->results, count($this->results), $perPage);
    }

    public function searchNearby(
        float $lat,
        float $lng,
        int $radiusKm = 50,
        array $filters = [],
        int $perPage = 20
    ): LengthAwarePaginator {
        $this->nearbyCalls++;

        return new Paginator($this->results, count($this->results), $perPage);
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
        ->and(collect($fake->searchArguments)->pluck('query')->all())->toEqualCanonicalizing(['kuliah tafsir', 'tauhid']);

    expect($service->recorded)->toHaveCount(1);
    $notification = $service->recorded[0];

    expect($notification->trigger)->toBe(NotificationTrigger::SavedSearchMatch)
        ->and($notification->forcedCadence)->toBe(NotificationCadence::Instant)
        ->and($notification->meta['saved_search_ids'])->toEqualCanonicalizing([
            $textSearchOne->id,
            $textSearchTwo->id,
            $nearbySearch->id,
        ]);
});
