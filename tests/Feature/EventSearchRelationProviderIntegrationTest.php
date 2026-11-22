<?php

declare(strict_types=1);

use App\Features\Discovery\Services\EventDiscoveryCriteriaFactory;
use App\Features\Events\Api\Data\EventDiscoveryHit;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Models\Event;
use App\Features\Events\Queries\EventCardsQuery;
use App\Features\Events\Queries\EventDiscoveryFilterSet;
use App\Features\Events\Queries\EventListPayloadPresenter;
use App\Features\Events\Queries\FuzzyEventMatcher;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Features\Events\Queries\TypesenseEventDiscovery;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\References\Api\ReferenceQueries;
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

    $discovery = new PostgresEventDiscovery(
        fuzzyMatcher: new FuzzyEventMatcher,
        filterSet: new EventDiscoveryFilterSet,
        personLookup: app(PersonLookup::class),
        institutionLookup: app(InstitutionLookup::class),
        placeCatalog: app(PlaceCatalog::class),
        categoryCatalog: app(EventCategoryCatalog::class),
        relationProvider: $provider,
        referenceQueries: app(ReferenceQueries::class),
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
