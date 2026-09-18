<?php

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use AIArmada\Addressing\Models\AddressAreaStateLink;
use App\Models\Institution;
use App\Support\Institutions\GeneratedPoskodInstitutionData;
use Database\Seeders\MalaysiaPoskodMasjidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('imports a postcode csv fixture against the production geography seed', function () {
    $fixturePath = base_path('tests/Fixtures/poskod_test_fixture.csv');

    $country = ensureTestMalaysiaCountry();

    /** @var array<string, array{district: string, subdistrict?: string}> $geographies */
    $geographies = [
        'Wilayah Persekutuan Kuala Lumpur' => ['district' => 'Kuala Lumpur'],
        'Terengganu' => ['district' => 'Hulu Terengganu'],
        'Perak' => ['district' => 'Kuala Kangsar'],
        'Kedah' => ['district' => 'Pokok Sena', 'subdistrict' => 'Bukit Lada'],
        'Sabah' => ['district' => 'Kinabatangan'],
        'Sarawak' => ['district' => 'Betong'],
    ];

    foreach ($geographies as $stateName => $geography) {
        createTestPackageGeography(
            stateName: $stateName,
            districtName: $geography['district'],
            subdistrictName: $geography['subdistrict'] ?? null,
            country: $country,
        );
    }

    $seeder = new MalaysiaPoskodMasjidSeeder($fixturePath);
    $seeder->run();

    $fixtureSlugs = slugsFromFixture($fixturePath);

    $expectedCount = count($fixtureSlugs);
    $postcodeInstitutions = fn () => Institution::query()->whereIn('slug', $fixtureSlugs);
    $findInstitution = fn (string $slug): ?Institution => Institution::query()
        ->where('slug', $slug)
        ->with(['addresses', 'addresses.areaAssignments'])
        ->first();

    expect($expectedCount)->toBe(6)
        ->and($postcodeInstitutions()->count())->toBe($expectedCount)
        ->and($postcodeInstitutions()->whereHas('addresses')->count())->toBe($expectedCount);

    $masjidNegara = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID NEGARA', '1'));
    expect($masjidNegara)->not()->toBeNull();
    expect($masjidNegara->primaryAddress()?->state)->toBe('Wilayah Persekutuan Kuala Lumpur');

    $menora = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID AL - MUNARIAH', '500'));
    expect($menora)->not()->toBeNull();
    expect($menora->primaryAddress()?->state)->toBe('Perak');

    $ajil = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID AJIL', '28'));
    expect($ajil)->not()->toBeNull();
    expect($ajil?->name)->toBe('Masjid Ajil');
    expect($ajil->primaryAddress()?->line1)->toBe('Ajil, Hulu Terengganu');

    $bracketedName = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('[01] MASJID KAMPUNG BUKIT LADA', '5667'));
    expect($bracketedName)->not()->toBeNull();
    expect($bracketedName?->name)->toBe('Masjid Kampung Bukit Lada');

    $estateName = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('(ESTATE) MASJID AL-MUHAJIRIN', '6412'));
    expect($estateName)->not()->toBeNull();
    expect($estateName?->name)->toBe('Masjid Al-Muhajirin (ESTATE)');

    $junkSarawak = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('masjid nurulllllllllllll', '6082'));
    expect($junkSarawak)->not()->toBeNull();
    expect($junkSarawak?->slug)->toBe('masjid-nurulllllllllllll-6082');
    expect($junkSarawak->primaryAddress()?->state)->toBe('Sarawak');
});

it('assigns a Putrajaya precinct when importing a postcode row', function () {
    $fixturePath = base_path('tests/Fixtures/poskod_putrajaya_test_fixture.csv');
    $country = ensureTestMalaysiaCountry();
    $geography = createTestPackageGeography(
        stateName: 'Wilayah Persekutuan Putrajaya',
        districtName: 'Putrajaya',
        country: $country,
    );
    createTestPackageGeography(
        stateName: 'Sarawak',
        districtName: 'Betong',
        country: $country,
    );

    AddressAreaStateLink::query()->create([
        'address_area_id' => $geography['area_tree_root']->getKey(),
        'state_id' => $geography['state']->getKey(),
        'hierarchy_type' => 'postal',
    ]);

    $precinct = AddressArea::query()->create([
        'country_id' => $country->getKey(),
        'country_code' => 'MY',
        'parent_id' => $geography['area_tree_root']->getKey(),
        'type' => 'precinct',
        'level' => 2,
        'name' => 'Precinct 3',
        'slug' => 'precinct-3',
        'source' => 'tests',
        'source_id' => (string) Str::ulid(),
        'parent_source_id' => $geography['area_tree_root']->source_id,
    ]);

    AddressAreaRelationship::query()->create([
        'parent_address_area_id' => $geography['area_tree_root']->getKey(),
        'child_address_area_id' => $precinct->getKey(),
        'relationship_type' => 'contains',
        'hierarchy_type' => 'postal',
        'source' => 'tests',
    ]);

    $seeder = new MalaysiaPoskodMasjidSeeder($fixturePath);
    $seeder->run();

    $institution = Institution::query()
        ->where('slug', GeneratedPoskodInstitutionData::canonicalSlug('MASJID PRESINT 3', '9001'))
        ->with(['addresses.areaAssignments'])
        ->first();

    expect($institution)->not()->toBeNull()
        ->and($institution?->primaryAddress()?->areaAssignments
            ->firstWhere('role', 'postal_locality')?->address_area_id)
        ->toBe((string) $precinct->getKey());
});

/**
 * @return list<string>
 */
function slugsFromFixture(string $fixturePath): array
{
    $handle = fopen($fixturePath, 'r');

    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle, escape: '\\');

    if (! is_array($header)) {
        fclose($handle);

        return [];
    }

    $normalizedHeader = array_map(
        static fn (string $value): string => ltrim($value, "\xEF\xBB\xBF"),
        $header,
    );

    $slugs = [];

    while (($row = fgetcsv($handle, escape: '\\')) !== false) {
        $mapped = array_combine($normalizedHeader, array_pad($row, count($normalizedHeader), ''));
        $rowNumber = trim((string) ($mapped['No.'] ?? ''));
        $name = (string) ($mapped['Nama'] ?? '');

        if ($rowNumber === '' || $name === '') {
            continue;
        }

        $slugs[] = GeneratedPoskodInstitutionData::canonicalSlug($name, $rowNumber);
    }

    fclose($handle);

    return $slugs;
}
