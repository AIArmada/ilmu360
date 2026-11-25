<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\Events\Features\Catalog\Actions\CreateEventOccurrenceAction;
use AIArmada\Events\Features\Catalog\Models\EventTimeExpression;
use App\Features\Events\Actions\Discovery\RefreshOccurrenceDiscoveryAction;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\Api\EventCards;
use App\Features\Events\EntryPoints\Mcp\McpEventSearchService;
use App\Features\Events\Models\Event;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Features\Events\Services\PublicScheduleDiscoveryService;
use App\Features\Events\Support\PublicScheduleLeaf;
use App\Features\Persons\Api\PersonSearchCache;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Services\PersonSearchService;
use App\Features\Places\Models\Venue;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Api\PrayerOffset;
use App\Features\Prayer\Api\PrayerReference;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Features\SavedSearches\Services\EventSavedSearchExecutor;
use App\Models\User;
use App\Platform\Location\VisitorCountryResolver;
use App\Platform\Search\DatabaseFuzzyMatcher;
use App\Platform\Search\ScoutRawHitExtractor;
use App\Shared\Cache\DiscoveryCache;
use App\Shared\Contracts\SearchEngineResolver;
use App\Shared\Contracts\SearchHealthCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    app('cache')->setDefaultDriver('array');
    Cache::flush();
    app(VisitorCountryResolver::class)->forget();
});

it('caches default majlis schedule page 1 and busts cleanly on occurrence mutation', function (): void {
    $this->freezeTime();

    $event = Event::factory()->create([
        'title' => 'Cached Default Majlis',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
    ]);

    $service = app(PublicScheduleDiscoveryService::class);
    $first = $service->search(null, [], 12, 'time');

    expect(collect($first->items())->map(fn (PublicScheduleLeaf $leaf): string => $leaf->title())->all())
        ->toContain('Cached Default Majlis');

    $versionKey = (string) config('discovery.cache.versions.events_schedule');
    $version = app(DiscoveryCache::class)->version($versionKey);
    $cacheKey = sprintf(
        'default_schedule_page:country:global:per:12:tz:%s:min:%s:%s',
        UserDateTimeFormatter::resolveTimezone(),
        now()->utc()->format('YmdHi'),
        $version,
    );

    expect(Cache::get($cacheKey))->toBeArray();

    // Mutate occurrence and verify RefreshOccurrenceDiscoveryAction busts cached page
    $secondEvent = Event::factory()->create([
        'title' => 'Newly Added Majlis',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);
    $occurrence = $secondEvent->occurrences()->firstOrFail();
    $occurrence->update(['title' => 'Updated Occurrence Title']);

    // afterCommit observer does not fire inside RefreshDatabase transaction; invoke action directly
    app(RefreshOccurrenceDiscoveryAction::class)->afterSaved((string) $event->getKey());

    expect(app(DiscoveryCache::class)->version($versionKey))->not->toBe($version);

    $refreshed = $service->search(null, [], 12, 'time');

    expect(collect($refreshed->items())->map(fn (PublicScheduleLeaf $leaf): string => $leaf->title())->all())
        ->toContain('Updated Occurrence Title');
});

it('falls back to live schedule query when DiscoveryCache fails on default schedule page', function (): void {
    Event::factory()->create([
        'title' => 'Schedule Cache Fallback Majlis',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);

    $cache = Mockery::mock(DiscoveryCache::class);
    $cache->shouldReceive('remember')
        ->once()
        ->andThrow(new RuntimeException('cache unavailable'));
    app()->instance(DiscoveryCache::class, $cache);

    $page = app(PublicScheduleDiscoveryService::class)->search(null, [], 12, 'time');

    expect(collect($page->items())->map(fn (PublicScheduleLeaf $leaf): string => $leaf->title())->all())
        ->toContain('Schedule Cache Fallback Majlis');
});

it('caches homepageSelection featured occurrences and busts on occurrence mutation', function (): void {
    $event = Event::factory()->create([
        'title' => 'Featured Cached Majlis',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
    ]);
    $occurrence = $event->occurrences()->firstOrFail();
    $occurrence->update(['is_featured' => true]);

    $service = app(PublicScheduleDiscoveryService::class);
    $first = $service->homepageSelection(null);

    expect($first->map(fn (PublicScheduleLeaf $leaf): string => $leaf->id())->all())
        ->toBe([(string) $occurrence->getKey()]);

    $versionKey = (string) config('discovery.cache.versions.events_schedule');
    $version = app(DiscoveryCache::class)->version($versionKey);

    expect(Cache::get("homepage_featured_occurrences:{$version}"))->toBeArray();

    // Feature another occurrence and bust via RefreshOccurrenceDiscoveryAction
    $second = Event::factory()->create([
        'title' => 'Second Featured Majlis',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);
    $secondOccurrence = $second->occurrences()->firstOrFail();
    $secondOccurrence->update(['is_featured' => true]);
    app(RefreshOccurrenceDiscoveryAction::class)->afterSaved((string) $second->getKey());

    $afterBust = $service->homepageSelection(null);

    expect($afterBust->map(fn (PublicScheduleLeaf $leaf): string => $leaf->id())->all())
        ->toBe([(string) $secondOccurrence->getKey(), (string) $occurrence->getKey()]);
});

it('preserves text query alongside geo coordinates across SearchController, EventSavedSearchExecutor, and McpEventSearchService', function (): void {
    config()->set('scout.driver', 'database');

    $venue = Venue::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($venue, [
        'latitude' => 3.1390,
        'longitude' => 101.6869,
    ]);

    $matching = Event::factory()->for($venue)->create([
        'title' => 'Kuliah Tafsir Nearby',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);

    $otherNearby = Event::factory()->for($venue)->create([
        'title' => 'Kuliah Fiqh Nearby',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);

    // 1. SearchController
    $httpResponse = $this->getJson('/api/v1/search?search=Tafsir&lat=3.1390&lng=101.6869&radius_km=15')
        ->assertOk();
    $httpTitles = collect($httpResponse->json('data.events.items'))->pluck('title')->all();

    expect($httpTitles)->toContain('Kuliah Tafsir Nearby')
        ->not->toContain('Kuliah Fiqh Nearby');

    // 2. EventSavedSearchExecutor
    $user = User::factory()->create();
    $snapshot = new SavedSearchSnapshot(
        id: (string) Str::uuid(),
        userType: $user->getMorphClass(),
        userId: (string) $user->getKey(),
        name: 'Geo Tafsir',
        query: 'Tafsir',
        filters: [],
        notify: null,
        lat: 3.1390,
        lng: 101.6869,
        radiusKm: 15,
        createdAt: CarbonImmutable::now(),
    );
    $savedSearchResult = app(EventSavedSearchExecutor::class)->execute($snapshot);
    $savedSearchIds = collect($savedSearchResult->items)->pluck('id')->all();

    expect($savedSearchIds)->toContain((string) $matching->getKey())
        ->not->toContain((string) $otherNearby->getKey());

    // 3. McpEventSearchService
    $mcpResult = app(McpEventSearchService::class)->search([
        'query' => 'Tafsir',
        'lat' => 3.1390,
        'lng' => 101.6869,
        'radius_km' => 15,
    ]);
    $mcpIds = collect($mcpResult['data'])->pluck('id')->all();

    expect($mcpIds)->toContain((string) $matching->getKey())
        ->not->toContain((string) $otherNearby->getKey());
});

it('executes zero EventCards::forIds hydration during PublicScheduleDiscoveryService fuzzy and geo searches', function (): void {
    config()->set('scout.driver', 'database');

    $venue = Venue::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($venue, [
        'latitude' => 3.1390,
        'longitude' => 101.6869,
    ]);

    Event::factory()->for($venue)->create([
        'title' => 'Majlis Melawati Perdana',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);

    $this->mock(EventCards::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('forIds');
    });

    $service = app(PublicScheduleDiscoveryService::class);

    // Fuzzy path (typo misses direct SQL LIKE, falls back to EventSearchReader::searchIds)
    $fuzzy = $service->search(query: 'Melawti');
    expect(collect($fuzzy->items())->map(fn (PublicScheduleLeaf $leaf): string => $leaf->title())->all())
        ->toContain('Majlis Melawati Perdana');

    // Geo + query path (uses EventSearchReader::searchNearbyIds)
    $geo = $service->search(
        query: 'Melawati',
        latitude: 3.1390,
        longitude: 101.6869,
        radiusKm: 15,
    );
    expect(collect($geo->items())->map(fn (PublicScheduleLeaf $leaf): string => $leaf->title())->all())
        ->toContain('Majlis Melawati Perdana');
});

it('paginates timing_mode and prayer_time filters in SQL respecting occurrence-level overrides and Tarawih isolation', function (): void {
    // Event 1: event-level Selepas Maghrib; Occurrence 1 inherits Maghrib, Occurrence 2 overrides with absolute mode
    $parentEvent = Event::factory()->create([
        'title' => 'Parent Prayer Programme',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'delivery_mode' => EventFormat::Physical->value,
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_reference' => PrayerReference::Maghrib,
        'prayer_offset' => PrayerOffset::Immediately,
        'prayer_display_text' => 'Selepas Maghrib',
    ]);
    $inheritedOcc = $parentEvent->occurrences()->firstOrFail();
    $inheritedOcc->update(['title' => 'Inherited Maghrib Occ']);

    $overriddenOcc = app(CreateEventOccurrenceAction::class)->handle($parentEvent, [
        'title' => 'Overridden Absolute Occ',
        'slug' => 'overridden-absolute-occ',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHour(),
        'status' => 'published',
        'visibility' => 'public',
    ]);
    EventTimeExpression::query()->create([
        'event_id' => $parentEvent->getKey(),
        'event_occurrence_id' => $overriddenOcc->getKey(),
        'time_mode' => 'absolute',
        'anchor_type' => null,
        'anchor_code' => null,
        'relation' => null,
        'display_label' => '21:00',
    ]);

    // Event 2: Tarawih (null anchor_code, label-only) vs Event 3: Isyak (anchor_code = isha)
    $tarawihEvent = Event::factory()->create([
        'title' => 'Tarawih SQL Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(4)->addHour(),
        'delivery_mode' => EventFormat::Physical->value,
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_reference' => null,
        'prayer_offset' => PrayerOffset::After60,
        'prayer_display_text' => 'Selepas Tarawih',
    ]);
    $tarawihOcc = $tarawihEvent->occurrences()->firstOrFail();

    $isyakEvent = Event::factory()->create([
        'title' => 'Isyak SQL Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(5)->addHour(),
        'delivery_mode' => EventFormat::Physical->value,
        'timing_mode' => TimingMode::PrayerRelative,
        'prayer_reference' => PrayerReference::Isha,
        'prayer_offset' => PrayerOffset::Immediately,
        'prayer_display_text' => 'Selepas Isyak',
    ]);
    $isyakOcc = $isyakEvent->occurrences()->firstOrFail();

    $postgres = app(PostgresEventDiscovery::class);

    $prayerRelativeIds = collect($postgres->paginatePublicScheduleOccurrences(null, ['timing_mode' => TimingMode::PrayerRelative->value], 1, 20)['rows'])
        ->pluck('occurrence_id')
        ->all();
    expect($prayerRelativeIds)
        ->toContain((string) $inheritedOcc->getKey(), (string) $tarawihOcc->getKey(), (string) $isyakOcc->getKey())
        ->not->toContain((string) $overriddenOcc->getKey());

    $absoluteIds = collect($postgres->paginatePublicScheduleOccurrences(null, ['timing_mode' => TimingMode::Absolute->value], 1, 20)['rows'])
        ->pluck('occurrence_id')
        ->all();
    expect($absoluteIds)
        ->toContain((string) $overriddenOcc->getKey())
        ->not->toContain((string) $inheritedOcc->getKey(), (string) $tarawihOcc->getKey(), (string) $isyakOcc->getKey());

    $tarawihIds = collect($postgres->paginatePublicScheduleOccurrences(null, ['prayer_time' => EventPrayerTime::SelepasTarawih->value], 1, 20)['rows'])
        ->pluck('occurrence_id')
        ->all();
    expect($tarawihIds)->toBe([(string) $tarawihOcc->getKey()]);

    $isyakIds = collect($postgres->paginatePublicScheduleOccurrences(null, ['prayer_time' => EventPrayerTime::SelepasIsyak->value], 1, 20)['rows'])
        ->pluck('occurrence_id')
        ->all();
    expect($isyakIds)->toBe([(string) $isyakOcc->getKey()]);

    $maghribFreeTextIds = collect($postgres->paginatePublicScheduleOccurrences(null, ['prayer_time' => 'Selepas Maghrib'], 1, 20)['rows'])
        ->pluck('occurrence_id')
        ->all();
    expect($maghribFreeTextIds)->toBe([(string) $inheritedOcc->getKey()]);
});

it('skips local DB scan in PersonSearchService::publicSearchIds when Typesense returns hits', function (): void {
    $person = Person::factory()->create([
        'name' => 'Ustaz Typesense Hit',
        'status' => 'verified',
    ]);

    config()->set('scout.driver', 'typesense');
    $healthCheck = Mockery::mock(SearchHealthCheck::class);
    $healthCheck->shouldReceive('isAvailable')->andReturn(true);
    app()->instance(SearchHealthCheck::class, $healthCheck);

    $service = new class(app(PersonWorkflow::class), app(PersonSearchCache::class), app(SearchEngineResolver::class), app(ScoutRawHitExtractor::class), app(DatabaseFuzzyMatcher::class), (string) $person->getKey()) extends PersonSearchService
    {
        public function __construct(
            PersonWorkflow $personWorkflow,
            PersonSearchCache $personSearchCache,
            SearchEngineResolver $engineResolver,
            ScoutRawHitExtractor $hitExtractor,
            DatabaseFuzzyMatcher $fuzzyMatcher,
            private readonly string $hitId,
        ) {
            parent::__construct($personWorkflow, $personSearchCache, $engineResolver, $hitExtractor, $fuzzyMatcher);
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            return [$this->hitId];
        }
    };

    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $ids = $service->publicSearchIds('Ustaz Typesense');

    expect($ids)->toBe([(string) $person->getKey()])
        ->and(collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, 'person_search_terms') || str_contains($sql, '"persons"'))->all())
        ->toBeEmpty();
});
