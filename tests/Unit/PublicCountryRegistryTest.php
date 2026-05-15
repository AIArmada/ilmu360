<?php

use App\Support\Location\PreferredCountryResolver;
use App\Support\Location\PublicCountryRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    if (! DB::table('countries')->where('id', PreferredCountryResolver::MALAYSIA_ID)->exists()) {
        DB::table('countries')->insert([
            'id' => PreferredCountryResolver::MALAYSIA_ID,
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);
    }
});

it('returns the single timezone for supported single-timezone public countries', function (string $countryKey, string $iso2, string $name, string $timezone) {
    config()->set("public-countries.countries.{$countryKey}.enabled", true);
    config()->set("public-countries.countries.{$countryKey}.coming_soon", false);

    $countryId = DB::table('countries')->insertGetId([
        'iso2' => $iso2,
        'name' => $name,
        'status' => 1,
        'phone_code' => $iso2 === 'BN' ? '673' : '65',
        'iso3' => $iso2 === 'BN' ? 'BRN' : 'SGP',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);

    app()->forgetInstance(PublicCountryRegistry::class);

    expect(app(PublicCountryRegistry::class)->singleTimezoneForCountryId($countryId))->toBe($timezone);
})->with([
    'singapore' => ['singapore', 'SG', 'Singapore', 'Asia/Singapore'],
    'brunei' => ['brunei', 'BN', 'Brunei', 'Asia/Brunei'],
]);

it('returns null for supported multi-timezone public countries', function () {
    config()->set('public-countries.countries.indonesia.enabled', true);
    config()->set('public-countries.countries.indonesia.coming_soon', false);

    $countryId = DB::table('countries')->insertGetId([
        'iso2' => 'ID',
        'name' => 'Indonesia',
        'status' => 1,
        'phone_code' => '62',
        'iso3' => 'IDN',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);

    app()->forgetInstance(PublicCountryRegistry::class);

    expect(app(PublicCountryRegistry::class)->singleTimezoneForCountryId($countryId))->toBeNull();
});

it('exposes country labels as the registry display field', function () {
    app()->forgetInstance(PublicCountryRegistry::class);

    $country = app(PublicCountryRegistry::class)->country('malaysia');

    expect($country['label'])->toBe('Malaysia')
        ->and($country['flag'])->toBe('🇲🇾')
        ->and($country['iso2'])->toBe('MY');
});
