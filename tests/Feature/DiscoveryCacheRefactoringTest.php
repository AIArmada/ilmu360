<?php

declare(strict_types=1);

use AIArmada\Addressing\Features\Geography\Models\City;
use AIArmada\Addressing\Features\Geography\Models\State;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Actions\Lifecycle\ApplyEventLifecycleEffectsAction;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\ShareCardWarmScheduler;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Persons\Models\Person;
use App\Features\References\Actions\Projection\SyncReferenceDirectoryEffectsAction;
use App\Features\References\Api\ReferenceListingCacheVersion;
use App\Features\References\Models\Reference;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;
use App\Platform\Language\Models\Language;
use App\Shared\Cache\DiscoveryCache;
use App\Shared\Cache\SelectionCatalog;
use App\Shared\Contracts\SearchHealthCheck;
use App\Shared\Contracts\SearchHealthProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Cache::flush();
});

it('emits Cache-Control, weak ETag, and 304 Not Modified on public catalog endpoints', function (): void {
    $country = ensureTestMalaysiaCountry();
    $state = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Catalog Cache Test State',
        'code' => 'CCT',
    ]);
    City::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'name' => 'Catalog Cache Test City',
    ]);
    Language::query()->firstOrCreate(
        ['code' => 'ms'],
        ['name' => 'Malay', 'native' => 'Bahasa Melayu', 'dir' => 'ltr'],
    );
    submitEventTerm('domain');
    $geo = createTestPackageGeography('Selangor Cache', 'Petaling Cache', 'Subang Cache', country: $country);

    $urls = [
        route('api.client.catalogs.countries'),
        route('api.client.catalogs.states', ['country_id' => $country->getKey()]),
        route('api.client.catalogs.cities', ['state_id' => $state->getKey()]),
        route('api.client.catalogs.administrative-districts', ['state_id' => $geo['state']->getKey()]),
        route('api.client.catalogs.administrative-subdivisions', ['administrative_district' => $geo['district']->getKey()]),
        route('api.client.catalogs.languages'),
        route('api.client.catalogs.donation-banks', ['country_id' => $country->getKey()]),
        route('api.client.catalogs.taxonomy-terms', ['type' => 'domain']),
    ];

    foreach ($urls as $url) {
        $first = $this->getJson($url)->assertOk();
        $etag = $first->headers->get('ETag');
        $cacheControl = (string) $first->headers->get('Cache-Control');

        expect($etag)->toStartWith('W/"')
            ->and($cacheControl)->toContain('public')
            ->and($cacheControl)->toContain('max-age=300')
            ->and($cacheControl)->toContain('stale-while-revalidate=600');

        $this->withHeaders(['If-None-Match' => (string) $etag])
            ->getJson($url)
            ->assertStatus(304);
    }

    // Bumping geography version invalidates the ETag for geography endpoints
    $countriesUrl = route('api.client.catalogs.countries');
    $before = (string) $this->getJson($countriesUrl)->assertOk()->headers->get('ETag');
    app(SelectionCatalog::class)->bustAddress();

    $this->withHeaders(['If-None-Match' => $before])
        ->getJson($countriesUrl)
        ->assertOk();
});

it('emits meta.cache.version on ReferenceDirectoryController and bumps it on reference sync effects', function (): void {
    $reference = Reference::factory()->create(['status' => 'verified', 'parent_id' => null]);

    $first = $this->getJson(route('api.client.references.index'))->assertOk();
    $versionBefore = $first->json('meta.cache.version');

    expect($versionBefore)->toBeString()->not->toBeEmpty()
        ->and($versionBefore)->toBe(app(ReferenceListingCacheVersion::class)->current()['version']);

    $reference->title = 'Updated Reference '.Str::random(6);
    $reference->save();

    app(SyncReferenceDirectoryEffectsAction::class)->saved($reference);

    $second = $this->getJson(route('api.client.references.index'))->assertOk();
    $versionAfter = $second->json('meta.cache.version');

    expect($versionAfter)->not->toBe($versionBefore);
});

it('busts discovery cache scopes and reports status via artisan commands', function (): void {
    $cache = app(DiscoveryCache::class);
    $eventsBefore = $cache->version((string) config('discovery.cache.versions.events_schedule'));
    $personsBefore = $cache->version((string) config('discovery.cache.versions.persons_search'));

    $this->artisan('discovery:cache-bust', ['--scope' => 'events'])
        ->assertSuccessful();

    expect($cache->version((string) config('discovery.cache.versions.events_schedule')))->not->toBe($eventsBefore)
        ->and($cache->version((string) config('discovery.cache.versions.persons_search')))->toBe($personsBefore);

    config()->set('discovery.search.driver');
    config()->set('scout.driver', 'typesense');
    config()->set('scout.typesense.client-settings', ['api_key' => 'test', 'nodes' => []]);
    $probe = Mockery::mock(SearchHealthProbe::class);
    $probe->shouldReceive('isHealthy')->twice()->andReturn(true, false);
    app()->instance(SearchHealthProbe::class, $probe);

    expect(app(SearchHealthCheck::class)->isAvailable())->toBeTrue();
    $this->artisan('discovery:cache-bust', ['--scope' => 'health'])
        ->assertSuccessful();
    expect(app(SearchHealthCheck::class)->isAvailable())->toBeFalse();

    $venuesBefore = $cache->version((string) config('discovery.cache.versions.venues_search'));
    $this->artisan('discovery:cache-bust', ['--scope' => 'venues'])
        ->assertSuccessful();
    expect($cache->version((string) config('discovery.cache.versions.venues_search')))->not->toBe($venuesBefore);

    $this->artisan('discovery:cache-bust', ['--scope' => 'all'])
        ->assertSuccessful();
    expect($cache->version((string) config('discovery.cache.versions.persons_search')))->not->toBe($personsBefore);

    $this->artisan('discovery:cache-bust', ['--scope' => 'invalid-scope'])
        ->assertFailed();

    $this->artisan('discovery:cache-status')
        ->expectsOutputToContain('discovery:v:events:schedule')
        ->assertSuccessful();
});

it('selectively bumps persons_featured only when featured-relevant attributes change', function (): void {
    $cache = app(DiscoveryCache::class);
    $featuredKey = (string) config('discovery.cache.versions.persons_featured');

    $person = Person::factory()->create([
        'status' => 'verified',
        'is_featured' => true,
        'bio' => 'Initial bio',
    ]);

    $versionAfterCreate = $cache->version($featuredKey);

    $person->update(['bio' => 'Unrelated bio update']);
    expect($cache->version($featuredKey))->toBe($versionAfterCreate);

    $person->update(['is_featured' => false]);
    $versionAfterFeatureChange = $cache->version($featuredKey);
    expect($versionAfterFeatureChange)->not->toBe($versionAfterCreate);

    $person->delete();
    expect($cache->version($featuredKey))->not->toBe($versionAfterFeatureChange);
});

it('skips public listing and homepage stats invalidation when updating a non-public event', function (): void {
    $invalidator = Mockery::mock(PublicListingsCacheInvalidator::class);
    $invalidator->shouldNotReceive('bustHomepageStats');
    $invalidator->shouldNotReceive('bustMajlisListing');

    $action = new ApplyEventLifecycleEffectsAction(
        eventAuthoring: app(EventAuthoring::class),
        slugRedirectRecorder: app(AIArmada\CommerceSupport\Contracts\SlugRedirectRecorder::class),
        slugRedirectCommands: app(SlugRedirectCommands::class),
        institutionCacheVersion: app(InstitutionListingCacheVersion::class),
        personCacheVersion: app(PersonListingCacheVersion::class),
        publicListingsCache: $invalidator,
        shareCards: app(ShareCardWarmScheduler::class),
    );

    $draftEvent = Event::factory()->create([
        'status' => 'draft',
        'visibility' => EventVisibility::Public,
        'published_at' => null,
    ]);

    $draftEvent->update(['description' => 'Draft description update']);
    $action->updated($draftEvent);

    $publicInvalidator = Mockery::mock(PublicListingsCacheInvalidator::class);
    $publicInvalidator->shouldReceive('bustHomepageStats')->once();
    $publicInvalidator->shouldReceive('bustMajlisListing')->once();

    $publicAction = new ApplyEventLifecycleEffectsAction(
        eventAuthoring: app(EventAuthoring::class),
        slugRedirectRecorder: app(AIArmada\CommerceSupport\Contracts\SlugRedirectRecorder::class),
        slugRedirectCommands: app(SlugRedirectCommands::class),
        institutionCacheVersion: app(InstitutionListingCacheVersion::class),
        personCacheVersion: app(PersonListingCacheVersion::class),
        publicListingsCache: $publicInvalidator,
        shareCards: app(ShareCardWarmScheduler::class),
    );

    $publicEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
    ]);

    $publicEvent->update(['description' => 'Public description update']);
    $publicAction->updated($publicEvent);
});
