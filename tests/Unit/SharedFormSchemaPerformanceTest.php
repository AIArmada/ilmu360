<?php

use App\Forms\SharedFormSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves a country address profile once while building repeated location labels', function (): void {
    $country = ensureTestMalaysiaCountry();
    $countryId = (string) $country->getKey();
    $countryQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$countryQueries): void {
        if (str_contains($query->sql, 'countries')) {
            $countryQueries++;
        }
    });

    SharedFormSchema::locationLevelLabel($countryId, 'administrative_district', 'District');
    $queriesAfterFirstLabel = $countryQueries;

    SharedFormSchema::locationLevelLabel($countryId, 'administrative_subdivision', 'Subdistrict');
    SharedFormSchema::locationLevelLabel($countryId, 'postal_locality', 'Locality');

    expect($queriesAfterFirstLabel)->toBeGreaterThan(0)
        ->and($countryQueries)->toBe($queriesAfterFirstLabel);
});

it('queries scoped types once while building repeated location labels', function (): void {
    app()->setLocale('en');

    $geography = createTestPackageGeography(stateName: 'Johor', districtName: 'Batu Pahat', subdistrictName: 'Parit Sulong');
    $countryId = (string) $geography['country']->getKey();
    $stateId = (string) $geography['state']->getKey();
    $areaIds = ['administrative_district' => (string) $geography['district']->getKey()];
    $typeQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$typeQueries): void {
        if (str_contains($query->sql, 'select distinct') && str_contains($query->sql, 'address_areas')) {
            $typeQueries++;
        }
    });

    for ($render = 0; $render < 3; $render++) {
        expect(SharedFormSchema::locationLevelLabel($countryId, 'administrative_subdivision', 'FALLBACK', $stateId, $areaIds))->toBe('Subdistrict');
    }

    expect($typeQueries)->toBe(1);
});
