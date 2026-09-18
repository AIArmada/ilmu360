<?php

use AIArmada\Addressing\Models\AddressAreaStateLink;
use AIArmada\Addressing\Models\State;
use App\Models\Venue;

use function Pest\Laravel\get;

it('filters venues down the Indonesian cascade with provider labels', function (): void {
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
    $cibinong = createTestAddressArea('Cibinong', 3, parent: $bandung, country: $indonesia, type: 'district');

    $shown = Venue::factory()->create(['name' => 'Dewan Cimahi Raya', 'status' => 'verified']);
    syncPrimaryAddressForTest($shown, [
        'country_id' => $indonesiaId,
        'state_id' => (string) $province->getKey(),
        'area_assignments' => [
            'regency' => (string) $bandung->getKey(),
            'district' => (string) $cimahi->getKey(),
        ],
    ]);

    $hidden = Venue::factory()->create(['name' => 'Dewan Cibinong Indah', 'status' => 'verified']);
    syncPrimaryAddressForTest($hidden, [
        'country_id' => $indonesiaId,
        'state_id' => (string) $province->getKey(),
        'area_assignments' => [
            'regency' => (string) $bandung->getKey(),
            'district' => (string) $cibinong->getKey(),
        ],
    ]);

    get('/tempat?'.http_build_query([
        'country_id' => $indonesiaId,
        'state_id' => (string) $province->getKey(),
        'district_id' => (string) $bandung->getKey(),
        'subdivision_id' => (string) $cimahi->getKey(),
    ]))
        ->assertSuccessful()
        ->assertSee((string) $province->getKey(), false)
        ->assertSee('Provinsi')
        ->assertSee('Kabupaten / Kota')
        ->assertSee('Daerah')
        ->assertSee('Dewan Cimahi Raya')
        ->assertDontSee('Dewan Cibinong Indah');
});

it('filters venues down the Singapore cascade without a state row', function (): void {
    $singapore = ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65');
    $singaporeId = (string) $singapore->getKey();

    State::query()->firstOrCreate(
        ['country_id' => $singaporeId, 'name' => 'North West'],
        ['code' => '03'],
    );

    $central = createTestAddressArea('Central', 1, country: $singapore, type: 'region');
    $bishan = createTestAddressArea('Bishan', 2, parent: $central, country: $singapore, type: 'planning_area');
    $geylang = createTestAddressArea('Geylang', 2, parent: $central, country: $singapore, type: 'planning_area');

    $shown = Venue::factory()->create(['name' => 'Dewan Bishan Prihatin', 'status' => 'verified']);
    syncPrimaryAddressForTest($shown, [
        'country_id' => $singaporeId,
        'area_assignments' => [
            'region' => (string) $central->getKey(),
            'planning_area' => (string) $bishan->getKey(),
        ],
    ]);

    $hidden = Venue::factory()->create(['name' => 'Dewan Geylang Serai', 'status' => 'verified']);
    syncPrimaryAddressForTest($hidden, [
        'country_id' => $singaporeId,
        'area_assignments' => [
            'region' => (string) $central->getKey(),
            'planning_area' => (string) $geylang->getKey(),
        ],
    ]);

    get('/tempat?country_id='.$singaporeId)
        ->assertSuccessful()
        ->assertDontSee('venue-state-filter', false)
        ->assertSee('Wilayah Perancangan');

    get('/tempat?'.http_build_query([
        'country_id' => $singaporeId,
        'district_id' => (string) $central->getKey(),
        'subdivision_id' => (string) $bishan->getKey(),
    ]))
        ->assertSuccessful()
        ->assertSee('Kawasan Perancangan')
        ->assertSee('Dewan Bishan Prihatin')
        ->assertDontSee('Dewan Geylang Serai');
});

it('keeps the Malaysian venue cascade filtering by district and subdivision', function (): void {
    $shown = createTestPackageGeography('Selangor', 'Petaling', 'Subang');
    $hidden = createTestPackageGeography('Johor', 'Johor Bahru', 'Tebrau');

    $shownVenue = Venue::factory()->create(['name' => 'Dewan Subang Jaya', 'status' => 'verified']);
    syncPrimaryAddressForTest($shownVenue, $shown['address']);

    $hiddenVenue = Venue::factory()->create(['name' => 'Dewan Tebrau Indah', 'status' => 'verified']);
    syncPrimaryAddressForTest($hiddenVenue, $hidden['address']);

    get('/tempat?'.http_build_query([
        'country_id' => (string) $shown['country']->getKey(),
        'state_id' => (string) $shown['state']->getKey(),
        'district_id' => (string) $shown['district']->getKey(),
        'subdivision_id' => (string) $shown['subdistrict']->getKey(),
    ]))
        ->assertSuccessful()
        ->assertSee('Daerah')
        ->assertSee('Mukim')
        ->assertSee('Petaling')
        ->assertSee('Dewan Subang Jaya')
        ->assertDontSee('Dewan Tebrau Indah');
});
