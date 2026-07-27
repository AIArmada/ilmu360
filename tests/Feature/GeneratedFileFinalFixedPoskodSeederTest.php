<?php

use App\Models\Institution;
use App\Support\Institutions\GeneratedPoskodInstitutionData;
use Database\Seeders\GeneratedFileFinalFixedPoskodSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('verifies the production csv has the expected row count', function () {
    $csvPath = database_path('seeders/Generated_File_Final_Fixed_Poskod.csv');

    $handle = fopen($csvPath, 'r');
    expect($handle)->not()->toBeFalse();

    $header = fgetcsv($handle, escape: '\\');
    expect($header)->toBeArray();

    $count = 0;
    while (fgetcsv($handle, escape: '\\') !== false) {
        $count++;
    }
    fclose($handle);

    expect($count)->toBe(6935);
});

it('imports a postcode csv fixture against the production geography seed', function () {
    $fixturePath = base_path('tests/Fixtures/poskod_test_fixture.csv');

    $this->seed(ProductionSeeder::class);

    $seeder = new GeneratedFileFinalFixedPoskodSeeder($fixturePath);
    $seeder->run();

    $fixtureSlugs = slugsFromFixture($fixturePath);

    $expectedCount = count($fixtureSlugs);
    $postcodeInstitutions = fn () => Institution::query()->whereIn('slug', $fixtureSlugs);
    $findInstitution = fn (string $slug): ?Institution => Institution::query()
        ->where('slug', $slug)
        ->with(['addresses', 'addresses.areaAssignments'])
        ->first();

    expect($expectedCount)->toBe(15)
        ->and($postcodeInstitutions()->count())->toBe($expectedCount)
        ->and($postcodeInstitutions()->whereHas('addresses')->count())->toBe($expectedCount);

    $menora = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID AL - MUNARIAH', '500'));
    expect($menora)->not()->toBeNull();
    expect($menora->primaryAddress()?->state)->toBe('Perak');

    $ajil = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID AJIL', '28'));
    expect($ajil)->not()->toBeNull();
    expect($ajil?->name)->toBe('Masjid Ajil');
    expect($ajil->primaryAddress()?->line1)->toBe('Ajil, Hulu Terengganu');

    $temerloh = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('MASJID ABU BAKAR TEMERLOH', '106'));
    expect($temerloh)->not()->toBeNull();
    expect($temerloh?->name)->toBe('Masjid Abu Bakar Temerloh');
    expect($temerloh->primaryAddress()?->line1)->toBe('Bandar Temerloh');

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

    $keladi = $findInstitution(GeneratedPoskodInstitutionData::canonicalSlug('ABDUL RAHMAN PUTRA KARIAH KELADI', '6809'));
    expect($keladi)->not()->toBeNull();
    expect($keladi?->slug)->toBe('abdul-rahman-putra-kariah-keladi-6809');
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
