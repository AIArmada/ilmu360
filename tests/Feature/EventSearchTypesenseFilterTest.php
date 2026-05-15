<?php

use App\Services\EventSearchService;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\ReferenceSearchService;
use App\Support\Search\SpeakerSearchService;
use App\Support\Search\TypesenseHealthCheckService;

/**
 * @return array{0: TypesenseHealthCheckService, 1: SpeakerSearchService, 2: InstitutionSearchService, 3: ReferenceSearchService}
 */
function eventSearchTypesenseFilterDependencies(): array
{
    return [
        new TypesenseHealthCheckService,
        new SpeakerSearchService,
        new InstitutionSearchService,
        new ReferenceSearchService,
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

    expect($filters)->toContain('is_active:=true');
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
        'subdistrict_id' => 321,
    ]);

    expect($filters)->toContain('subdistrict_id:=321');
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

    $filters = $service->exposedBuildTypesenseFilterParts([
        'source_tag_ids' => ['source-1'],
        'issue_tag_ids' => ['issue-1'],
        'reference_ids' => ['ref-1'],
    ]);

    expect($filters)
        ->toContain('source_tag_ids:[source-1]')
        ->toContain('issue_tag_ids:[issue-1]')
        ->toContain('reference_ids:[ref-1]');
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
