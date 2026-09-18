<?php

use AIArmada\Addressing\Models\AddressAreaStateLink;
use AIArmada\Addressing\Models\State;

use function Pest\Laravel\getJson;

it('serves provider-driven area options through the catalog endpoints', function (): void {
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');
    $indonesiaId = (string) $indonesia->getKey();

    $province = State::query()->firstOrCreate(
        ['country_id' => $indonesiaId, 'name' => 'Jawa Barat'],
        ['code' => null],
    );
    $provinceArea = createTestAddressArea('Jawa Barat', 1, country: $indonesia, type: 'province');
    AddressAreaStateLink::query()->firstOrCreate(
        ['address_area_id' => $provinceArea->getKey(), 'state_id' => $province->getKey()],
        ['hierarchy_type' => 'administrative'],
    );
    $bandung = createTestAddressArea('Bandung', 2, parent: $provinceArea, country: $indonesia, type: 'regency');
    $cimahi = createTestAddressArea('Cimahi', 3, parent: $bandung, country: $indonesia, type: 'district');

    getJson('/api/v1/catalogs/administrative-districts?state_id='.$province->getKey())
        ->assertOk()
        ->assertJsonFragment(['id' => (string) $bandung->getKey(), 'label' => 'Bandung', 'type' => 'regency']);

    // Country is inferred from the district when omitted.
    getJson('/api/v1/catalogs/administrative-subdivisions?administrative_district='.$bandung->getKey())
        ->assertOk()
        ->assertJsonFragment(['id' => (string) $cimahi->getKey(), 'label' => 'Cimahi', 'type' => 'district']);
});

it('serves the stateless Singapore cascade without states', function (): void {
    $singapore = ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65');
    $singaporeId = (string) $singapore->getKey();

    State::query()->firstOrCreate(
        ['country_id' => $singaporeId, 'name' => 'North West'],
        ['code' => '03'],
    );

    $central = createTestAddressArea('Central', 1, country: $singapore, type: 'region');
    $bishan = createTestAddressArea('Bishan', 2, parent: $central, country: $singapore, type: 'planning_area');

    getJson('/api/v1/catalogs/states?country_id='.$singaporeId)
        ->assertOk()
        ->assertJsonPath('data', []);

    getJson('/api/v1/catalogs/administrative-districts?country_id='.$singaporeId)
        ->assertOk()
        ->assertJsonFragment(['id' => (string) $central->getKey(), 'label' => 'Central', 'type' => 'region']);

    getJson('/api/v1/catalogs/administrative-subdivisions?country_id='.$singaporeId.'&administrative_district='.$central->getKey())
        ->assertOk()
        ->assertJsonFragment(['id' => (string) $bishan->getKey(), 'label' => 'Bishan', 'type' => 'planning_area']);
});

it('keeps the Malaysian catalog cascade unchanged', function (): void {
    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Subang');

    getJson('/api/v1/catalogs/states?country_id='.$geo['country']->getKey())
        ->assertOk()
        ->assertJsonFragment(['id' => (string) $geo['state']->getKey()]);

    getJson('/api/v1/catalogs/administrative-districts?state_id='.$geo['state']->getKey())
        ->assertOk()
        ->assertJsonFragment(['id' => (string) $geo['district']->getKey(), 'type' => 'district']);

    getJson('/api/v1/catalogs/administrative-subdivisions?administrative_district='.$geo['district']->getKey())
        ->assertOk()
        ->assertJsonFragment(['id' => (string) $geo['subdistrict']->getKey(), 'type' => 'subdistrict']);
});
