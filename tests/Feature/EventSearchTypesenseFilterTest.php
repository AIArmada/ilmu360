<?php

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use App\Contracts\EventCategoryCatalog;
use App\Data\EventDiscoveryCriteriaFactory;
use App\Services\TypesenseEventDiscovery;
use App\Support\EventDiscovery\EventDiscoveryFilterSet;
use Illuminate\Support\Str;

/**
 * @return array{0: EventDiscoveryFilterSet, 1: EventCategoryCatalog, 2: EventSearchRelationProvider}
 */
function typesenseDiscoveryDependencies(): array
{
    return [
        new EventDiscoveryFilterSet,
        app(EventCategoryCatalog::class),
        app(EventSearchRelationProvider::class),
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

test('typesense filters include active event constraint', function () {
    $discovery = exposedTypesenseDiscovery();

    $filters = $discovery->exposedBuildTypesenseFilterParts([]);

    expect($filters)->toContain('status:[approved, pending, cancelled]');
});

test('typesense filters include subdistrict constraint when provided', function () {
    $discovery = exposedTypesenseDiscovery();

    $filters = $discovery->exposedBuildTypesenseFilterParts([
        'area_assignments' => [
            'administrative_subdivision' => '321',
        ],
    ]);

    expect($filters)->toContain('administrative_subdivision:=321');
});

test('typesense filters include country constraint when provided', function () {
    $discovery = exposedTypesenseDiscovery();

    $filters = $discovery->exposedBuildTypesenseFilterParts([
        'country_id' => 132,
    ]);

    expect($filters)->toContain('country_id:=132');
});

test('country filter alone does not force database fallback', function () {
    $factory = new EventDiscoveryCriteriaFactory;

    expect($factory->fromSearch(null, ['country_id' => '132'], 20, 'time')->requiresDatabaseFiltering)->toBeFalse();
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

    $hasOverlapFilter = collect($filters)->contains(
        static fn (string $filter): bool => str_contains($filter, 'ends_at:>=')
            && str_contains($filter, '||starts_at:>=')
    );

    expect($hasOverlapFilter)->toBeTrue();
});
