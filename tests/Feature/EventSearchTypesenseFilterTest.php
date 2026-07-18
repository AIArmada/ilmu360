<?php

use App\Contracts\EventCategoryCatalog;
use App\Data\EventDiscoveryCriteriaFactory;
use App\Services\EventSearchService;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\ReferenceSearchService;
use App\Support\Search\SpeakerSearchService;
use App\Support\Search\TypesenseHealthCheckService;
use Illuminate\Support\Str;

test('discovery criteria are normalized deterministically before search execution', function () {
    $factory = new EventDiscoveryCriteriaFactory;
    $countryId = (string) Str::uuid();
    $filters = [
        'country_id' => $countryId,
        'state_id' => 'not-a-uuid',
        'speaker_ids' => [$countryId, 'invalid'],
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
        ->and($first->relationFilters['speaker_ids'])->toBe([$countryId])
        ->and($first->startsAfterUtc?->getTimezone()->getName())->toBe('UTC')
        ->and($first->requiresDatabaseFiltering)->toBeTrue()
        ->and($first->filters)->not->toHaveKey('empty');
});

/**
 * @return array{0: TypesenseHealthCheckService, 1: SpeakerSearchService, 2: InstitutionSearchService, 3: ReferenceSearchService, 4: EventCategoryCatalog}
 */
function eventSearchTypesenseFilterDependencies(): array
{
    return [
        new TypesenseHealthCheckService,
        new SpeakerSearchService,
        new InstitutionSearchService,
        new ReferenceSearchService,
        app(EventCategoryCatalog::class),
    ];
}

test('typesense filters include active event constraint', function () {
    $service = new class extends EventSearchService
    {
        public function __construct()
        {
            parent::__construct(...eventSearchTypesenseFilterDependencies());
        }

        /**
         * @param  array<string, mixed>  $filters
         * @return array<int, string>
         */
        public function exposedBuildTypesenseFilterParts(array $filters): array
        {
            return $this->buildTypesenseFilterParts($filters);
        }
    };

    $filters = $service->exposedBuildTypesenseFilterParts([]);

    expect($filters)->toContain('status:[approved, pending, cancelled]');
});

test('typesense filters include subdistrict constraint when provided', function () {
    $service = new class extends EventSearchService
    {
        public function __construct()
        {
            parent::__construct(...eventSearchTypesenseFilterDependencies());
        }

        /**
         * @param  array<string, mixed>  $filters
         * @return array<int, string>
         */
        public function exposedBuildTypesenseFilterParts(array $filters): array
        {
            return $this->buildTypesenseFilterParts($filters);
        }
    };

    $filters = $service->exposedBuildTypesenseFilterParts([
        'admin_area_2_id' => 321,
    ]);

    expect($filters)->toContain('admin_area_2_id:=321');
});

test('typesense filters include country constraint when provided', function () {
    $service = new class extends EventSearchService
    {
        public function __construct()
        {
            parent::__construct(...eventSearchTypesenseFilterDependencies());
        }

        /**
         * @param  array<string, mixed>  $filters
         * @return array<int, string>
         */
        public function exposedBuildTypesenseFilterParts(array $filters): array
        {
            return $this->buildTypesenseFilterParts($filters);
        }
    };

    $filters = $service->exposedBuildTypesenseFilterParts([
        'country_id' => 132,
    ]);

    expect($filters)->toContain('country_id:=132');
});

test('country filter alone does not force database fallback', function () {
    $service = new class extends EventSearchService
    {
        public function __construct()
        {
            parent::__construct(...eventSearchTypesenseFilterDependencies());
        }

        /**
         * @param  array<string, mixed>  $filters
         */
        public function exposedRequiresDatabaseFiltering(array $filters): bool
        {
            return $this->requiresDatabaseFiltering($filters);
        }
    };

    expect($service->exposedRequiresDatabaseFiltering([
        'country_id' => 132,
    ]))->toBeFalse();
});

test('reference author searches force database fallback', function () {
    $factory = new EventDiscoveryCriteriaFactory;
    $service = new class extends EventSearchService
    {
        public function __construct()
        {
            parent::__construct(...eventSearchTypesenseFilterDependencies());
        }

        /**
         * @param  array<string, mixed>  $filters
         */
        public function exposedRequiresDatabaseFiltering(array $filters): bool
        {
            return $this->requiresDatabaseFiltering($filters);
        }
    };

    expect($factory->fromSearch(null, ['reference_author_search' => 'Muhammad Abduh'], 20, 'time')->requiresDatabaseFiltering)
        ->toBeTrue()
        ->and($factory->fromSearch(null, ['reference_author_search' => ['Muhammad Abduh']], 20, 'time')->requiresDatabaseFiltering)
        ->toBeTrue()
        ->and($service->exposedRequiresDatabaseFiltering(['reference_author_search' => 'Muhammad Abduh']))
        ->toBeTrue()
        ->and($service->exposedRequiresDatabaseFiltering(['reference_author_search' => ['Muhammad Abduh']]))
        ->toBeTrue();
});

test('typesense filters include domain tag ids constraint when provided', function () {
    $service = new class extends EventSearchService
    {
        public function __construct()
        {
            parent::__construct(...eventSearchTypesenseFilterDependencies());
        }

        /**
         * @param  array<string, mixed>  $filters
         * @return array<int, string>
         */
        public function exposedBuildTypesenseFilterParts(array $filters): array
        {
            return $this->buildTypesenseFilterParts($filters);
        }
    };

    $filters = $service->exposedBuildTypesenseFilterParts([
        'domain_tag_ids' => ['tag-1', 'tag-2'],
    ]);

    expect($filters)->toContain('domain_tag_ids:[tag-1,tag-2]');
});

test('typesense filters include source, issue, and reference constraints when provided', function () {
    $service = new class extends EventSearchService
    {
        public function __construct()
        {
            parent::__construct(...eventSearchTypesenseFilterDependencies());
        }

        /**
         * @param  array<string, mixed>  $filters
         * @return array<int, string>
         */
        public function exposedBuildTypesenseFilterParts(array $filters): array
        {
            return $this->buildTypesenseFilterParts($filters);
        }
    };

    $referenceId = (string) Str::uuid();

    $filters = $service->exposedBuildTypesenseFilterParts([
        'source_tag_ids' => ['source-1'],
        'issue_tag_ids' => ['issue-1'],
        'reference_ids' => [$referenceId],
    ]);

    expect($filters)
        ->toContain('source_tag_ids:[source-1]')
        ->toContain('issue_tag_ids:[issue-1]')
        ->toContain('reference_ids:['.$referenceId.']');
});

test('typesense filters include linked PIC profile ids and free-text PIC search forces database fallback', function () {
    $service = new class extends EventSearchService
    {
        public function __construct()
        {
            parent::__construct(...eventSearchTypesenseFilterDependencies());
        }

        /**
         * @param  array<string, mixed>  $filters
         * @return array<int, string>
         */
        public function exposedBuildTypesenseFilterParts(array $filters): array
        {
            return $this->buildTypesenseFilterParts($filters);
        }

        /**
         * @param  array<string, mixed>  $filters
         */
        public function exposedRequiresDatabaseFiltering(array $filters): bool
        {
            return $this->requiresDatabaseFiltering($filters);
        }
    };

    $filters = $service->exposedBuildTypesenseFilterParts([
        'person_in_charge_ids' => ['speaker-1'],
    ]);

    expect($filters)
        ->toContain('person_in_charge_ids:[speaker-1]')
        ->and($service->exposedRequiresDatabaseFiltering([
            'person_in_charge_search' => 'Ahmad',
        ]))->toBeTrue();
});

test('typesense starts_after filter uses held-period overlap semantics', function () {
    $service = new class extends EventSearchService
    {
        public function __construct()
        {
            parent::__construct(...eventSearchTypesenseFilterDependencies());
        }

        /**
         * @param  array<string, mixed>  $filters
         * @return array<int, string>
         */
        public function exposedBuildTypesenseFilterParts(array $filters): array
        {
            return $this->buildTypesenseFilterParts($filters);
        }
    };

    $filters = $service->exposedBuildTypesenseFilterParts([
        'time_scope' => 'all',
        'starts_after' => '2026-03-20',
    ]);

    $hasOverlapFilter = collect($filters)->contains(
        static fn (string $filter): bool => str_contains($filter, 'ends_at:>=')
            && str_contains($filter, '||starts_at:>=')
    );

    expect($hasOverlapFilter)->toBeTrue();
});
