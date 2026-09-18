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
    'district follows the provider district level' => ['administrative_district', 'District / Jajahan / Jajahan Kecil'],
    'subdivision follows the provider subdivision level' => ['administrative_subdivision', 'Mukim / Subdistrict / Bandar / Pekan'],
]);

it('falls back to the generic label for countries without a provider', function (string $role): void {
    $countryId = (string) ensureTestAddressCountry('SG', 'Singapore')->getKey();

    expect(SharedFormSchema::locationLevelLabel($countryId, $role, 'FALLBACK'))->toBe('FALLBACK');
})->with([
    'state' => ['state_id'],
    'district' => ['administrative_district'],
    'subdivision' => ['administrative_subdivision'],
]);
