<?php

declare(strict_types=1);

use App\Features\Discovery\Services\EventDiscoveryCriteriaFactory;
use App\Features\Discovery\Services\EventDiscoveryFilterSet;
use App\Features\Discovery\Services\FuzzyEventMatcher;
use App\Features\Discovery\Services\PostgresEventDiscovery;
use App\Features\Discovery\Services\TypesenseEventDiscovery;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Services\InstitutionSearchService;
use App\Features\Persons\Services\PersonSearchService;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Services\ReferenceSearchService;
use App\Shared\Events\EventCategoryCatalog;
use Tests\Support\RecordingEventSearchRelationProvider;

it('makes PostgreSQL discovery consume the injected relation provider', function (): void {
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
        personSearch: app(PersonSearchService::class),
        institutionSearch: app(InstitutionSearchService::class),
        referenceSearch: app(ReferenceSearchService::class),
        categoryCatalog: app(EventCategoryCatalog::class),
        relationProvider: $provider,
        referenceQueries: app(ReferenceQueries::class),
    );

    $results = $discovery->search($criteria);
    $hydratedEvent = collect($results->items())->firstWhere('id', $event->id);

    expect($provider->calls)->toBeGreaterThan(0)
        ->and($hydratedEvent)->toBeInstanceOf(Event::class);

    if ($hydratedEvent instanceof Event) {
        expect($hydratedEvent->relationLoaded('languageRecords'))->toBeTrue();
    }
});

it('makes Typesense discovery consume the injected relation provider', function (): void {
    config()->set('scout.driver', 'database');

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

    $discovery = new TypesenseEventDiscovery(
        filterSet: new EventDiscoveryFilterSet,
        categoryCatalog: app(EventCategoryCatalog::class),
        relationProvider: $provider,
        referenceQueries: app(ReferenceQueries::class),
    );

    $results = $discovery->search($criteria);
    $hydratedEvent = collect($results->items())->firstWhere('id', $event->id);

    expect($provider->calls)->toBeGreaterThan(0)
        ->and($hydratedEvent)->toBeInstanceOf(Event::class);

    if ($hydratedEvent instanceof Event) {
        expect($hydratedEvent->relationLoaded('languageRecords'))->toBeTrue();
    }
});
