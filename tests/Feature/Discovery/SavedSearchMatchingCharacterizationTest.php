<?php

declare(strict_types=1);

use App\Features\Communications\Api\NotificationCadence;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\Discovery\Services\EventSearchService;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\User;
use Tests\Support\Discovery\D0CountingEventSearchService;
use Tests\Support\Discovery\D0SavedSearchNotificationRecorder;

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
