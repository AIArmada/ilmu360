<?php

declare(strict_types=1);

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use AIArmada\Events\Features\Search\Actions\UpsertEventSearchDocumentAction;
use AIArmada\Events\Features\Search\Models\EventSearchDocument;
use App\Features\Discovery\Services\EventDiscoveryCriteriaFactory;
use App\Features\Events\Api\Data\EventDiscoveryHit;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\EventScheduleSql;
use App\Features\Events\Models\Event;
use App\Features\Events\Queries\EventCardsQuery;
use App\Features\Events\Queries\EventCategoryPresenter;
use App\Features\Events\Queries\EventDiscoveryFilterSet;
use App\Features\Events\Queries\EventListPayloadPresenter;
use App\Features\Events\Queries\FuzzyEventMatcher;
use App\Features\Events\Queries\Postgres\EventFilterQueryBuilder;
use App\Features\Events\Queries\Postgres\EventHomepageAndCollectionQueries;
use App\Features\Events\Queries\Postgres\EventScheduleHydrator;
use App\Features\Events\Queries\Postgres\EventScheduleOccurrencePaginator;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Features\Events\Queries\PublicReferenceConstraint;
use App\Features\Events\Queries\TypesenseEventDiscovery;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\Places\Api\SpaceLocation;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Models\Reference;
use App\Platform\Location\SpatialAddressQuery;
use App\Platform\Search\DatabaseFuzzyMatcher;
use Illuminate\Support\Str;
use Tests\Support\RecordingEventSearchRelationProvider;

it('returns immutable PostgreSQL search hits without importing event models into Discovery', function (): void {
    $provider = new RecordingEventSearchRelationProvider;
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
        'timing_mode' => 'absolute',
    ]);
    $criteria = app(EventDiscoveryCriteriaFactory::class)->fromSearch(null, [], 20, 'time');

    $referenceQueries = app(ReferenceQueries::class);
    $publicReferenceConstraint = new PublicReferenceConstraint($referenceQueries);
    $filterQueryBuilder = new EventFilterQueryBuilder(
        fuzzyMatcher: new FuzzyEventMatcher(new DatabaseFuzzyMatcher),
        filterSet: new EventDiscoveryFilterSet,
        personLookup: app(PersonLookup::class),
        institutionLookup: app(InstitutionLookup::class),
        placeCatalog: app(PlaceCatalog::class),
        categoryCatalog: app(EventCategoryCatalog::class),
        referenceQueries: $referenceQueries,
        publicReferenceConstraint: $publicReferenceConstraint,
        spatialAddressQuery: app(SpatialAddressQuery::class),
    );
    $scheduleHydrator = new EventScheduleHydrator($provider);
    $schedulePaginator = new EventScheduleOccurrencePaginator($filterQueryBuilder, $scheduleHydrator);
    $homepageAndCollectionQueries = new EventHomepageAndCollectionQueries(
        filterQueryBuilder: $filterQueryBuilder,
        schedulePaginator: $schedulePaginator,
        scheduleHydrator: $scheduleHydrator,
        personLookup: app(PersonLookup::class),
        referenceQueries: $referenceQueries,
        eventListPayloads: app(EventListPayloadPresenter::class),
        eventScheduleSql: app(EventScheduleSql::class),
        publicReferenceConstraint: $publicReferenceConstraint,
        eventCategories: app(EventCategoryPresenter::class),
        spaceLocation: app(SpaceLocation::class),
    );

    $discovery = new PostgresEventDiscovery(
        filterQueryBuilder: $filterQueryBuilder,
        schedulePaginator: $schedulePaginator,
        homepageAndCollectionQueries: $homepageAndCollectionQueries,
        scheduleHydrator: $scheduleHydrator,
    );

    $hit = collect($discovery->search($criteria)->items())->firstWhere('id', (string) $event->getKey());

    expect($hit)->toBeInstanceOf(EventDiscoveryHit::class)
        ->and($hit->title)->toBe((string) $event->title)
        ->and($provider->calls)->toBe(0);
});

it('returns immutable Typesense search hits without eager model relations', function (): void {
    config()->set('scout.driver', 'database');

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
        'timing_mode' => 'absolute',
    ]);
    $criteria = app(EventDiscoveryCriteriaFactory::class)->fromSearch(null, [], 20, 'time');

    $discovery = new TypesenseEventDiscovery(
        filterSet: new EventDiscoveryFilterSet,
        categoryCatalog: app(EventCategoryCatalog::class),
        referenceQueries: app(ReferenceQueries::class),
    );

    $hit = collect($discovery->search($criteria)->items())->firstWhere('id', (string) $event->getKey());

    expect($hit)->toBeInstanceOf(EventDiscoveryHit::class)
        ->and($hit->title)->toBe((string) $event->title);
});

it('uses the injected search relation provider only when the owning feature renders full event cards', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);
    $provider = new RecordingEventSearchRelationProvider;
    $cards = new EventCardsQuery($provider, app(EventListPayloadPresenter::class));

    $cards->forIds([(string) $event->getKey()]);

    expect($provider->calls)->toBeGreaterThan(0);
});

it('loads active event references through the configured relation provider', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);
    $reference = Reference::factory()->create([
        'status' => 'verified',
        'published_at' => now(),
    ]);
    $event->references()->attach($reference, [
        'id' => (string) Str::uuid(),
        'sort_order' => 0,
    ]);

    $loaded = Event::query()
        ->with(app(EventSearchRelationProvider::class)->relations())
        ->whereKey($event->getKey())
        ->firstOrFail();

    expect($loaded->references)->toHaveCount(1)
        ->and((string) $loaded->references->first()->getKey())->toBe((string) $reference->getKey());
});

it('builds an event search document through the configured relation provider', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
    ]);

    config()->set('events.sync.build_search_documents', true);

    app(UpsertEventSearchDocumentAction::class)->handle(Event::class, (string) $event->getKey());

    $document = EventSearchDocument::query()
        ->where('searchable_type', (new Event)->getMorphClass())
        ->where('searchable_id', (string) $event->getKey())
        ->where('document_type', 'event')
        ->first();

    expect($document)->not->toBeNull()
        ->and($document->title)->toBe((string) $event->title)
        ->and($document->event_id)->toBe((string) $event->getKey());
});
