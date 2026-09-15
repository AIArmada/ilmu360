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
