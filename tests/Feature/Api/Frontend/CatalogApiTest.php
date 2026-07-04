<?php

use App\Models\Venue;

it('requires an explicit country for public states catalog options', function () {
    $malaysia = ensureTestMalaysiaCountry();
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');

    $malaysiaState = createTestAddressArea('Catalog API Selangor', 1, null, $malaysia);
    $indonesiaState = createTestAddressArea('Catalog API Jawa Barat', 1, null, $indonesia);

    $omittedResponse = $this->getJson(route('api.client.catalogs.states'))
        ->assertOk();

    $explicitResponse = $this->getJson(route('api.client.catalogs.states', ['country_id' => $indonesia->getKey()]))
        ->assertOk();

    expect($omittedResponse->json('data'))->toBe([])
        ->and(collect($explicitResponse->json('data'))->pluck('label')->all())
        ->toContain('Catalog API Jawa Barat')
        ->not->toContain('Catalog API Selangor')
        ->and(collect($explicitResponse->json('data'))->pluck('id')->all())
        ->toContain((string) $indonesiaState->getKey())
        ->not->toContain((string) $malaysiaState->getKey());
});

it('requires an explicit state or country for public districts catalog options', function () {
    $malaysia = ensureTestMalaysiaCountry();
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');

    $malaysiaState = createTestAddressArea('Catalog API Negeri Malaysia', 1, null, $malaysia);
    $indonesiaState = createTestAddressArea('Catalog API Provinsi Indonesia', 1, null, $indonesia);

    $malaysiaDistrict = createTestAddressArea('Catalog API Petaling', 2, $malaysiaState, $malaysia);
    $indonesiaDistrict = createTestAddressArea('Catalog API Bandung', 2, $indonesiaState, $indonesia);

    $omittedResponse = $this->getJson(route('api.client.catalogs.districts'))
        ->assertOk();

    $explicitResponse = $this->getJson(route('api.client.catalogs.districts', ['state_id' => $indonesiaState->getKey()]))
        ->assertOk();

    expect($omittedResponse->json('data'))->toBe([])
        ->and(collect($explicitResponse->json('data'))->pluck('label')->all())
        ->toContain('Catalog API Bandung')
        ->not->toContain('Catalog API Petaling')
        ->and(collect($explicitResponse->json('data'))->pluck('id')->all())
        ->toContain((string) $indonesiaDistrict->getKey())
        ->not->toContain((string) $malaysiaDistrict->getKey());
});

it('honors explicit country filters for public district catalog options', function () {
    $malaysia = ensureTestMalaysiaCountry();
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');

    $malaysiaState = createTestAddressArea('Catalog API Default Malaysia State', 1, null, $malaysia);
    $indonesiaState = createTestAddressArea('Catalog API Preferred Indonesia State', 1, null, $indonesia);

    createTestAddressArea('Catalog API Default Malaysia District', 2, $malaysiaState, $malaysia);
    createTestAddressArea('Catalog API Preferred Indonesia District', 2, $indonesiaState, $indonesia);

    $districtsResponse = $this
        ->getJson(route('api.client.catalogs.districts', ['country_id' => $indonesia->getKey()]))
        ->assertOk();

    expect(collect($districtsResponse->json('data'))->pluck('label')->all())
        ->toContain('Catalog API Preferred Indonesia District')
        ->not->toContain('Catalog API Default Malaysia District');
});

it('returns public venue catalog options for active visible venues', function () {
    Venue::factory()->create([
        'name' => 'Catalog API Visible Venue',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Venue::factory()->create([
        'name' => 'Catalog API Pending Venue',
        'status' => 'pending',
        'is_active' => true,
    ]);

    Venue::factory()->create([
        'name' => 'Catalog API Rejected Venue',
        'status' => 'rejected',
        'is_active' => true,
    ]);

    Venue::factory()->create([
        'name' => 'Catalog API Inactive Venue',
        'status' => 'verified',
        'is_active' => false,
    ]);

    $response = $this->getJson(route('api.client.catalogs.venues'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('label')->all())
        ->toContain('Catalog API Visible Venue', 'Catalog API Pending Venue')
        ->not->toContain('Catalog API Rejected Venue', 'Catalog API Inactive Venue');
});
