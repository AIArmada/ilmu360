<?php

use App\Forms\SharedFormSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves location labels from the country provider when one exists', function (string $role, string $expected): void {
    app()->setLocale('en');

    $countryId = (string) ensureTestMalaysiaCountry()->getKey();

    expect(SharedFormSchema::locationLevelLabel($countryId, $role, 'FALLBACK'))->toBe($expected);
})->with([
    'state follows the provider region level' => ['state_id', 'State / Federal Territory'],
    'district follows the provider district level' => ['administrative_district', 'District / Jajahan / Jajahan Kecil / Daerah Kecil'],
    'subdivision follows the provider subdivision level' => ['administrative_subdivision', 'Mukim / Subdistrict / Bandar / Pekan / Daerah Kecil'],
]);

it('falls back to the generic label for countries without a provider', function (string $role): void {
    $countryId = (string) ensureTestAddressCountry('SG', 'Singapore')->getKey();

    expect(SharedFormSchema::locationLevelLabel($countryId, $role, 'FALLBACK'))->toBe('FALLBACK');
})->with([
    'state' => ['state_id'],
    'district' => ['administrative_district'],
    'subdivision' => ['administrative_subdivision'],
]);

it('translates the individual types in scoped compound labels', function (string $locale, string $role, array $types, string $expected): void {
    app()->setLocale($locale);

    $geography = createTestPackageGeography(stateName: 'Johor', districtName: 'Batu Pahat');
    $parent = $role === 'administrative_district' ? $geography['area_tree_root'] : $geography['district'];
    $level = $role === 'administrative_district' ? 2 : 3;

    foreach ($types as $type) {
        createTestAddressArea('Test '.$type, $level, parent: $parent, country: $geography['country'], type: $type);
    }

    expect(SharedFormSchema::locationLevelLabel(
        (string) $geography['country']->getKey(),
        $role,
        'FALLBACK',
        (string) $geography['state']->getKey(),
        ['administrative_district' => (string) $geography['district']->getKey()],
    ))->toBe($expected);
})->with([
    'English mixed subdivisions' => ['en', 'administrative_subdivision', ['mukim', 'subdistrict'], 'Mukim / Subdistrict'],
    'Malay mixed subdivisions' => ['ms', 'administrative_subdivision', ['mukim', 'subdistrict'], 'Mukim / Daerah Kecil'],
    'regional Malay mixed subdivisions' => ['ms_MY', 'administrative_subdivision', ['mukim', 'subdistrict'], 'Mukim / Daerah Kecil'],
    'English mixed districts' => ['en', 'administrative_district', ['minor_district'], 'District / Minor District'],
    'Malay mixed districts' => ['ms', 'administrative_district', ['minor_district'], 'Daerah / Daerah Kecil'],
    'regional Malay mixed districts' => ['ms_MY', 'administrative_district', ['minor_district'], 'Daerah / Daerah Kecil'],
    'English city and municipality' => ['en', 'administrative_subdivision', ['city', 'municipality'], 'City / Municipality'],
    'Malay city and municipality' => ['ms', 'administrative_subdivision', ['city', 'municipality'], 'Bandar / Perbandaran'],
    'regional Malay city and municipality' => ['ms_MY', 'administrative_subdivision', ['city', 'municipality'], 'Bandar / Perbandaran'],
]);

it('preserves the existing full-phrase translation for an unscoped label', function (string $locale): void {
    app()->setLocale($locale);

    expect(SharedFormSchema::locationLevelLabel(
        (string) ensureTestMalaysiaCountry()->getKey(),
        'administrative_subdivision',
        'FALLBACK',
    ))->toBe('Mukim / Bandar / Pekan');
})->with(['ms', 'ms_MY']);

it('preserves state-specific district terminology in translated mixed labels', function (string $locale, string $stateName, string $stateCode, string $expected): void {
    app()->setLocale($locale);

    $geography = createTestPackageGeography(stateName: $stateName, districtName: 'Test District');
    $geography['state']->forceFill(['code' => $stateCode])->save();
    createTestAddressArea('Test Minor District', 2, parent: $geography['area_tree_root'], country: $geography['country'], type: 'minor_district');

    expect(SharedFormSchema::locationLevelLabel(
        (string) $geography['country']->getKey(),
        'administrative_district',
        'FALLBACK',
        (string) $geography['state']->getKey(),
    ))->toBe($expected);
})->with([
    'English Kelantan' => ['en', 'Kelantan', '03', 'Jajahan / Jajahan Kecil'],
    'Malay Kelantan' => ['ms', 'Kelantan', '03', 'Jajahan / Jajahan Kecil'],
    'regional Malay Kelantan' => ['ms_MY', 'Kelantan', '03', 'Jajahan / Jajahan Kecil'],
    'English Pahang' => ['en', 'Pahang', '06', 'District / Daerah Kecil'],
    'Malay Pahang' => ['ms', 'Pahang', '06', 'Daerah / Daerah Kecil'],
    'regional Malay Pahang' => ['ms_MY', 'Pahang', '06', 'Daerah / Daerah Kecil'],
]);

it('translates cached scope types using the current locale', function (): void {
    $geography = createTestPackageGeography(stateName: 'Johor', districtName: 'Batu Pahat', subdistrictName: 'Test Subdivision');
    $countryId = (string) $geography['country']->getKey();
    $stateId = (string) $geography['state']->getKey();
    $areaIds = ['administrative_district' => (string) $geography['district']->getKey()];

    app()->setLocale('en');
    expect(SharedFormSchema::locationLevelLabel($countryId, 'administrative_subdivision', 'FALLBACK', $stateId, $areaIds))->toBe('Subdistrict');

    app()->setLocale('ms');
    expect(SharedFormSchema::locationLevelLabel($countryId, 'administrative_subdivision', 'FALLBACK', $stateId, $areaIds))->toBe('Daerah Kecil');
});

it('preserves the proper term for a minor-district-only Pahang scope', function (string $locale): void {
    app()->setLocale($locale);

    $geography = createTestPackageGeography(stateName: 'Pahang', districtName: 'Test District');
    $geography['state']->forceFill(['code' => '06'])->save();
    $geography['district']->forceFill(['is_active' => false])->save();
    createTestAddressArea('Genting', 2, parent: $geography['area_tree_root'], country: $geography['country'], type: 'minor_district');

    expect(SharedFormSchema::locationLevelLabel(
        (string) $geography['country']->getKey(),
        'administrative_district',
        'FALLBACK',
        (string) $geography['state']->getKey(),
    ))->toBe('Daerah Kecil');
})->with(['en', 'ms', 'ms_MY']);
