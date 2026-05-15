<?php

use App\Models\Venue;
use App\Support\Location\PreferredCountryResolver;
use App\Support\Location\PublicCountryRegistry;
use Illuminate\Support\Facades\DB;

it('defaults the public states catalog to the configured public country when country_id is omitted', function () {
    $malaysiaId = ensureCatalogApiCountryExists('MY', 'Malaysia', PreferredCountryResolver::MALAYSIA_ID);
    $indonesiaId = ensureCatalogApiCountryExists('ID', 'Indonesia');

    $malaysiaStateId = DB::table('states')->insertGetId([
        'country_id' => $malaysiaId,
        'name' => 'Catalog API Selangor',
        'country_code' => 'MY',
    ]);

    $indonesiaStateId = DB::table('states')->insertGetId([
        'country_id' => $indonesiaId,
        'name' => 'Catalog API Jawa Barat',
        'country_code' => 'ID',
    ]);

    app()->forgetInstance(PublicCountryRegistry::class);

    $defaultResponse = $this->getJson(route('api.client.catalogs.states'))
        ->assertOk();

    $explicitResponse = $this->getJson(route('api.client.catalogs.states', ['country_id' => $indonesiaId]))
        ->assertOk();

    expect(collect($defaultResponse->json('data'))->pluck('label')->all())
        ->toContain('Catalog API Selangor')
        ->not->toContain('Catalog API Jawa Barat')
        ->and(collect($explicitResponse->json('data'))->pluck('id')->all())
        ->toContain($indonesiaStateId)
        ->not->toContain($malaysiaStateId);
});

it('defaults the public districts catalog to the configured public country when state_id is omitted', function () {
    $malaysiaId = ensureCatalogApiCountryExists('MY', 'Malaysia', PreferredCountryResolver::MALAYSIA_ID);
    $indonesiaId = ensureCatalogApiCountryExists('ID', 'Indonesia');

    $malaysiaStateId = DB::table('states')->insertGetId([
        'country_id' => $malaysiaId,
        'name' => 'Catalog API Negeri Malaysia',
        'country_code' => 'MY',
    ]);

    $indonesiaStateId = DB::table('states')->insertGetId([
        'country_id' => $indonesiaId,
        'name' => 'Catalog API Provinsi Indonesia',
        'country_code' => 'ID',
    ]);

    $malaysiaDistrictId = DB::table('districts')->insertGetId([
        'country_id' => $malaysiaId,
        'state_id' => $malaysiaStateId,
        'name' => 'Catalog API Petaling',
        'country_code' => 'MY',
    ]);

    $indonesiaDistrictId = DB::table('districts')->insertGetId([
        'country_id' => $indonesiaId,
        'state_id' => $indonesiaStateId,
        'name' => 'Catalog API Bandung',
        'country_code' => 'ID',
    ]);

    app()->forgetInstance(PublicCountryRegistry::class);

    $defaultResponse = $this->getJson(route('api.client.catalogs.districts'))
        ->assertOk();

    $explicitResponse = $this->getJson(route('api.client.catalogs.districts', ['state_id' => $indonesiaStateId]))
        ->assertOk();

    expect(collect($defaultResponse->json('data'))->pluck('label')->all())
        ->toContain('Catalog API Petaling')
        ->not->toContain('Catalog API Bandung')
        ->and(collect($explicitResponse->json('data'))->pluck('id')->all())
        ->toContain($indonesiaDistrictId)
        ->not->toContain($malaysiaDistrictId);
});

it('uses request-scoped public country resolution for default states and districts catalogs', function () {
    config()->set('public-countries.countries.indonesia.enabled', true);
    config()->set('public-countries.countries.indonesia.coming_soon', false);

    $malaysiaId = ensureCatalogApiCountryExists('MY', 'Malaysia', PreferredCountryResolver::MALAYSIA_ID);
    $indonesiaId = ensureCatalogApiCountryExists('ID', 'Indonesia');

    $malaysiaStateId = DB::table('states')->insertGetId([
        'country_id' => $malaysiaId,
        'name' => 'Catalog API Default Malaysia State',
        'country_code' => 'MY',
    ]);

    $indonesiaStateId = DB::table('states')->insertGetId([
        'country_id' => $indonesiaId,
        'name' => 'Catalog API Preferred Indonesia State',
        'country_code' => 'ID',
    ]);

    DB::table('districts')->insertGetId([
        'country_id' => $malaysiaId,
        'state_id' => $malaysiaStateId,
        'name' => 'Catalog API Default Malaysia District',
        'country_code' => 'MY',
    ]);

    DB::table('districts')->insertGetId([
        'country_id' => $indonesiaId,
        'state_id' => $indonesiaStateId,
        'name' => 'Catalog API Preferred Indonesia District',
        'country_code' => 'ID',
    ]);

    app()->forgetInstance(PublicCountryRegistry::class);

    $statesResponse = $this
        ->withHeader('X-Timezone', 'Asia/Jakarta')
        ->getJson(route('api.client.catalogs.states'))
        ->assertOk();

    $districtsResponse = $this
        ->withHeader('X-Timezone', 'Asia/Jakarta')
        ->getJson(route('api.client.catalogs.districts'))
        ->assertOk();

    expect(collect($statesResponse->json('data'))->pluck('label')->all())
        ->toContain('Catalog API Preferred Indonesia State')
        ->not->toContain('Catalog API Default Malaysia State')
        ->and(collect($districtsResponse->json('data'))->pluck('label')->all())
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

function ensureCatalogApiCountryExists(string $iso2, string $name, ?int $id = null): int
{
    if (is_int($id)) {
        $existingById = DB::table('countries')->where('id', $id)->value('id');

        if (is_int($existingById)) {
            return $existingById;
        }
    }

    $existingByIso2 = DB::table('countries')->where('iso2', strtoupper($iso2))->value('id');

    if (is_int($existingByIso2)) {
        return $existingByIso2;
    }

    $attributes = [
        'iso2' => strtoupper($iso2),
        'name' => $name,
        'status' => 1,
        'phone_code' => $iso2 === 'MY' ? '60' : '62',
        'iso3' => $iso2 === 'MY' ? 'MYS' : 'IDN',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ];

    if (is_int($id)) {
        $attributes['id'] = $id;
    }

    return DB::table('countries')->insertGetId($attributes);
}
