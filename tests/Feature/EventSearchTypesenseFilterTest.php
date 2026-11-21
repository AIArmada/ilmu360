<?php

use App\Features\Discovery\Services\EventDiscoveryCriteriaFactory;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Models\Event;
use App\Features\Events\Queries\EventDiscoveryFilterSet;
use App\Features\Events\Queries\TypesenseEventDiscovery;
use App\Features\References\Api\ReferenceQueries;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\TypesenseEngine;
use Mockery;

/**
 * @return array{0: EventDiscoveryFilterSet, 1: EventCategoryCatalog, 2: ReferenceQueries}
 */
function typesenseDiscoveryDependencies(): array
{
    return [
        new EventDiscoveryFilterSet,
        app(EventCategoryCatalog::class),
        app(ReferenceQueries::class),
    ];
}

/**
 * Create an anonymous class extending TypesenseEventDiscovery
 * that exposes protected methods for testing.
 */
function exposedTypesenseDiscovery(): TypesenseEventDiscovery
{
    return new class(...typesenseDiscoveryDependencies()) extends TypesenseEventDiscovery
    {
        /**
         * @param  array<string, mixed>  $filters
         * @return array<int, string>
         */
        public function exposedBuildTypesenseFilterParts(array $filters): array
        {
            return $this->buildTypesenseFilterParts($filters);
        }
    };
}

test('discovery criteria are normalized deterministically before search execution', function () {
    $factory = new EventDiscoveryCriteriaFactory;
    $countryId = (string) Str::uuid();
    $filters = [
        'country_id' => $countryId,
        'state_id' => 'not-a-uuid',
        'person_ids' => [$countryId, 'invalid'],
        'starts_after' => '2026-07-16',
        'starts_before' => '2026-07-20',
        'venue_id' => $countryId,
        'empty' => '   ',
    ];

    $first = $factory->fromSearch('  tafsir  ', $filters, 20, 'time');
    $second = $factory->fromSearch(' tafsir ', $filters, 20, 'time');

    expect($first)->toEqual($second)
        ->and($first->text)->toBe('tafsir')
        ->and($first->countryId)->toBe($countryId)
        ->and($first->stateId)->toBeNull()
        ->and($first->relationFilters['person_ids'])->toBe([$countryId])
        ->and($first->startsAfterUtc?->getTimezone()->getName())->toBe('UTC')
        ->and($first->requiresDatabaseFiltering)->toBeTrue()
        ->and($first->filters)->not->toHaveKey('empty');
});

test('typesense filters restrict discovery to public event statuses', function () {
    $discovery = exposedTypesenseDiscovery();

    $filters = $discovery->exposedBuildTypesenseFilterParts([]);

    expect($filters)->toContain('status:=[approved,pending,cancelled]');
});

test('typesense filters include role-keyed area constraint when provided', function () {
    $discovery = exposedTypesenseDiscovery();
    $areaId = (string) Str::uuid();

    $filters = $discovery->exposedBuildTypesenseFilterParts([
        'area_assignments' => [
            'administrative_subdivision' => $areaId,
        ],
    ]);

    expect($filters)->toContain('administrative_subdivision:='.$areaId);
});

test('typesense filters include country constraint when provided', function () {
    $discovery = exposedTypesenseDiscovery();
    $countryId = (string) Str::uuid();

    $filters = $discovery->exposedBuildTypesenseFilterParts([
        'country_id' => $countryId,
    ]);

    expect($filters)->toContain('country_id:='.$countryId);
});

test('country filter alone does not force database fallback', function () {
    $factory = new EventDiscoveryCriteriaFactory;
    $countryId = (string) Str::uuid();

    expect($factory->fromSearch(null, ['country_id' => $countryId], 20, 'time')->requiresDatabaseFiltering)->toBeFalse();
});

test('reference author searches force database fallback', function () {
    $factory = new EventDiscoveryCriteriaFactory;

    expect($factory->fromSearch(null, ['reference_author_search' => 'Muhammad Abduh'], 20, 'time')->requiresDatabaseFiltering)
        ->toBeTrue()
        ->and($factory->fromSearch(null, ['reference_author_search' => ['Muhammad Abduh']], 20, 'time')->requiresDatabaseFiltering)
        ->toBeTrue();
});

test('typesense filters include domain tag ids constraint when provided', function () {
    $discovery = exposedTypesenseDiscovery();

    $filters = $discovery->exposedBuildTypesenseFilterParts([
        'domain_tag_ids' => ['tag-1', 'tag-2'],
    ]);

    expect($filters)->toContain('domain_tag_ids:[tag-1,tag-2]');
});

test('typesense filters include source, issue, and reference constraints when provided', function () {
    $discovery = exposedTypesenseDiscovery();
    $referenceId = (string) Str::uuid();

    $filters = $discovery->exposedBuildTypesenseFilterParts([
        'source_tag_ids' => ['source-1'],
        'issue_tag_ids' => ['issue-1'],
        'reference_ids' => [$referenceId],
    ]);

    expect($filters)
        ->toContain('source_tag_ids:[source-1]')
        ->toContain('issue_tag_ids:[issue-1]')
        ->toContain('reference_ids:['.$referenceId.']');
});

test('typesense filters include linked PIC profile ids', function () {
    $discovery = exposedTypesenseDiscovery();

    $filters = $discovery->exposedBuildTypesenseFilterParts([
        'person_in_charge_ids' => ['person-1'],
    ]);

    expect($filters)->toContain('person_in_charge_ids:[person-1]');
});

test('typesense starts_after filter uses held-period overlap semantics', function () {
    $discovery = exposedTypesenseDiscovery();

    $filters = $discovery->exposedBuildTypesenseFilterParts([
        'time_scope' => 'all',
        'starts_after' => '2026-03-20',
    ]);

    $overlapFilter = collect($filters)->first(
        static fn (string $filter): bool => str_starts_with($filter, 'ends_at:>='),
    );

    expect($overlapFilter)->not->toBeNull();
});

test('typesense past scope filters by the end instant', function () {
    $discovery = exposedTypesenseDiscovery();

    $filters = $discovery->exposedBuildTypesenseFilterParts([
        'time_scope' => 'past',
    ]);

    expect(collect($filters)->contains(static fn (string $filter): bool => str_starts_with($filter, 'ends_at:<')))
        ->toBeTrue();
});

test('typesense rehydration revalidates current event visibility and publication', function () {
    config(['scout.driver' => 'database']);

    $stalePrivate = Event::factory()->create([
        'title' => 'Stale Rehydration Private Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $staleUnpublished = Event::factory()->create([
        'title' => 'Stale Rehydration Unpublished Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(3),
    ]);
    $fresh = Event::factory()->create([
        'title' => 'Stale Rehydration Current Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(4),
    ]);

    // Search-engine filters read indexed values; the current rows are now
    // non-public while the engine still matches the same query text.
    $stalePrivate->updateQuietly(['visibility' => 'private']);
    $staleUnpublished->updateQuietly(['published_at' => null]);

    $discovery = app(TypesenseEventDiscovery::class);
    $factory = app(EventDiscoveryCriteriaFactory::class);
    $expectedIds = [(string) $fresh->getKey()];

    $results = $discovery->search($factory->fromSearch('Stale Rehydration', [], 20, 'time'));
    $nearby = $discovery->nearby($factory->fromSearch(null, [], 20, 'distance', 3.139, 101.6869, 20));
    $nearbyQuery = $discovery->nearbyWithQuery($factory->fromSearch(
        'Stale Rehydration',
        [],
        20,
        'distance',
        3.139,
        101.6869,
        20,
    ));

    expect(collect($results->items())->pluck('id')->all())->toBe($expectedIds)
        ->and(collect($nearby->items())->pluck('id')->all())->toBe($expectedIds)
        ->and(collect($nearbyQuery->items())->pluck('id')->all())->toBe($expectedIds);
});

test('typesense rehydration drops stale ids returned by the index', function () {
    $fresh = Event::factory()->create([
        'title' => 'Fresh Indexed Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(2),
    ]);
    $stalePrivate = Event::factory()->create([
        'title' => 'Stale Indexed Private Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(3),
    ]);
    $staleUnpublished = Event::factory()->create([
        'title' => 'Stale Indexed Unpublished Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDays(4),
    ]);
    $stalePrivate->updateQuietly(['visibility' => 'private']);
    $staleUnpublished->updateQuietly(['published_at' => null]);

    $engine = Mockery::mock(TypesenseEngine::class)->makePartial();
    $engine->shouldReceive('paginate')
        ->once()
        ->andReturn([
            'found' => 3,
            'hits' => [
                ['document' => ['id' => (string) $stalePrivate->getKey()]],
                ['document' => ['id' => (string) $fresh->getKey()]],
                ['document' => ['id' => (string) $staleUnpublished->getKey()]],
            ],
        ]);

    app(EngineManager::class)->extend('stale-typesense', fn () => $engine);
    config(['scout.driver' => 'stale-typesense', 'scout.queue' => false]);

    $discovery = app(TypesenseEventDiscovery::class);
    $criteria = app(EventDiscoveryCriteriaFactory::class)->fromSearch('Stale Index', [], 20, 'time');
    $results = $discovery->search($criteria);

    // Scout recomputes the paginator total through the query callback, so both
    // the hydrated payload and the total exclude stale current-state rows.
    expect(collect($results->items())->pluck('id')->all())->toBe([(string) $fresh->getKey()])
        ->and($results->total())->toBe(1);
});
