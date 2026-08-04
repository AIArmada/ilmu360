<?php

declare(strict_types=1);

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use App\Contracts\EventCategoryCatalog;
use App\Data\EventDiscoveryCriteriaFactory;
use App\Models\Event;
use App\Services\PostgresEventDiscovery;
use App\Services\TypesenseEventDiscovery;
use App\Support\EventDiscovery\EventDiscoveryFilterSet;
use App\Support\EventDiscovery\FuzzyEventMatcher;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\PersonSearchService;
use App\Support\Search\ReferenceSearchService;

final class RecordingEventSearchRelationProvider implements EventSearchRelationProvider
{
    public int $calls = 0;

    public function relations(): array
    {
        $this->calls++;

        return ['languageRecords'];
    }
}

it('makes PostgreSQL discovery consume the injected relation provider', function (): void {
    $provider = new RecordingEventSearchRelationProvider;
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
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
    $event = Event::factory()->create();
    $criteria = app(EventDiscoveryCriteriaFactory::class)->fromSearch(null, [], 20, 'time');

    $discovery = new TypesenseEventDiscovery(
        filterSet: new EventDiscoveryFilterSet,
        categoryCatalog: app(EventCategoryCatalog::class),
        relationProvider: $provider,
    );

    $results = $discovery->search($criteria);
    $hydratedEvent = collect($results->items())->firstWhere('id', $event->id);

    expect($provider->calls)->toBeGreaterThan(0)
        ->and($hydratedEvent)->toBeInstanceOf(Event::class);

    if ($hydratedEvent instanceof Event) {
        expect($hydratedEvent->relationLoaded('languageRecords'))->toBeTrue();
    }
});
