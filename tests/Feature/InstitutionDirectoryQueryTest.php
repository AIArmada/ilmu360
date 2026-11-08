<?php

use App\Models\Institution;
use App\Support\Search\InstitutionDirectoryQuery;
use Illuminate\Support\Facades\DB;

function institutionDirectoryPaginationMeta(): array
{
    return [
        'path' => '/institusi',
        'query' => [],
    ];
}

it('paginates direct institution search without resolving every matching id', function () {
    foreach (range(1, 15) as $index) {
        Institution::factory()->create([
            'name' => sprintf('Masjid Ujian %02d', $index),
            'status' => 'verified',
        ]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $paginator = app(InstitutionDirectoryQuery::class)->paginate(
        search: 'Masjid Ujian',
        locationIds: [],
        followedOnly: false,
        followingInstitutionIds: [],
        page: 1,
        paginationMeta: institutionDirectoryPaginationMeta(),
    );

    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($paginator->total())->toBe(15)
        ->and($paginator->count())->toBe(12)
        ->and($queries->filter(
            static fn (string $query): bool => str_contains($query, 'select "institutions"."id"'),
        ))->toBeEmpty()
        ->and($queries->filter(
            static fn (string $query): bool => str_contains($query, 'count(*) over ()'),
        ))->toHaveCount(1)
        ->and($queries->filter(
            static fn (string $query): bool => str_contains($query, 'count(*) as "aggregate"'),
        ))->toBeEmpty();
});

it('falls back to fuzzy search when direct search has no matches', function () {
    Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    $paginator = app(InstitutionDirectoryQuery::class)->paginate(
        search: 'Hidayh',
        locationIds: [],
        followedOnly: false,
        followingInstitutionIds: [],
        page: 1,
        paginationMeta: institutionDirectoryPaginationMeta(),
    );

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->name)->toBe('Masjid Al Hidayah');
});

it('applies location scope to direct search', function () {
    $selangor = createTestPackageGeography('Selangor Directory Scope', 'Petaling Directory Scope');
    $johor = createTestPackageGeography('Johor Directory Scope', 'Johor Bahru Directory Scope');

    $selangorInstitution = Institution::factory()->create([
        'name' => 'Masjid Ujian Lokasi Selangor',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($selangorInstitution, $selangor['address']);

    $johorInstitution = Institution::factory()->create([
        'name' => 'Masjid Ujian Lokasi Johor',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($johorInstitution, $johor['address']);

    $paginator = app(InstitutionDirectoryQuery::class)->paginate(
        search: 'Masjid Ujian Lokasi',
        locationIds: [
            'country_id' => (string) $selangor['country']->getKey(),
            'state_id' => (string) $selangor['state']->getKey(),
            'city_id' => null,
            'locality_id' => null,
            'district_id' => null,
            'subdivision_id' => null,
            'area_ids' => [],
        ],
        followedOnly: false,
        followingInstitutionIds: [],
        page: 1,
        paginationMeta: institutionDirectoryPaginationMeta(),
    );

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->name)->toBe('Masjid Ujian Lokasi Selangor');
});

it('preserves multi-word token matching', function () {
    Institution::factory()->create([
        'name' => 'Masjid Besi Putrajaya',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    Institution::factory()->create([
        'name' => 'Pusat Komuniti Besi',
        'status' => 'verified',
    ]);

    $paginator = app(InstitutionDirectoryQuery::class)->paginate(
        search: 'masjid besi',
        locationIds: [],
        followedOnly: false,
        followingInstitutionIds: [],
        page: 1,
        paginationMeta: institutionDirectoryPaginationMeta(),
    );

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->name)->toBe('Masjid Besi Putrajaya');
});

it('returns an empty page with the correct total beyond the last direct search page', function () {
    foreach (range(1, 3) as $index) {
        Institution::factory()->create([
            'name' => sprintf('Masjid Ujian Hujung %02d', $index),
            'status' => 'verified',
        ]);
    }

    $paginator = app(InstitutionDirectoryQuery::class)->paginate(
        search: 'Masjid Ujian Hujung',
        locationIds: [],
        followedOnly: false,
        followingInstitutionIds: [],
        page: 99,
        paginationMeta: institutionDirectoryPaginationMeta(),
    );

    expect($paginator->total())->toBe(3)
        ->and($paginator->items())->toBe([]);
});

it('returns an empty page with the correct total beyond the last fuzzy search page', function () {
    Institution::factory()->create([
        'name' => 'Masjid Al Hidayah',
        'status' => 'verified',
    ]);

    $paginator = app(InstitutionDirectoryQuery::class)->paginate(
        search: 'Hidayh',
        locationIds: [],
        followedOnly: false,
        followingInstitutionIds: [],
        page: 99,
        paginationMeta: institutionDirectoryPaginationMeta(),
    );

    expect($paginator->total())->toBe(1)
        ->and($paginator->items())->toBe([]);
});
