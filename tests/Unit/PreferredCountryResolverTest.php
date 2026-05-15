<?php

use App\Support\Location\PreferredCountryResolver;
use App\Support\Location\PublicCountryPreference;
use App\Support\Location\PublicCountryRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    if (! DB::table('countries')->where('id', 132)->exists()) {
        DB::table('countries')->insert([
            'id' => 132,
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

it('falls back to Malaysia when the saved user timezone resolves to a disabled country', function () {
    DB::table('countries')->insertGetId([
        'iso2' => 'ID',
        'name' => 'Indonesia',
        'status' => 1,
        'phone_code' => '62',
        'iso3' => 'IDN',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);

    $request = Request::create(route('events.index', [], false), 'GET');
    $request->cookies->set('user_timezone', 'Asia/Jakarta');

    $resolved = app(PreferredCountryResolver::class)->resolveId($request);

    expect($resolved)->toBe(PreferredCountryResolver::MALAYSIA_ID);
});

it('falls back to Malaysia when CF-IPCountry resolves to a disabled country', function () {
    DB::table('countries')->insertGetId([
        'iso2' => 'ID',
        'name' => 'Indonesia',
        'status' => 1,
        'phone_code' => '62',
        'iso3' => 'IDN',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);

    $request = Request::create(route('events.index', [], false), 'GET');
    $request->headers->set('CF-IPCountry', 'ID');

    $resolved = app(PreferredCountryResolver::class)->resolveId($request);

    expect($resolved)->toBe(PreferredCountryResolver::MALAYSIA_ID);
});

it('prefers the selected country over the viewer timezone', function () {
    config()->set('public-countries.countries.singapore.enabled', true);

    $singaporeId = DB::table('countries')->insertGetId([
        'iso2' => 'SG',
        'name' => 'Singapore',
        'status' => 1,
        'phone_code' => '65',
        'iso3' => 'SGP',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);

    app()->forgetInstance(PublicCountryRegistry::class);
    app()->forgetInstance(PublicCountryPreference::class);

    $request = Request::create(route('events.index', [], false), 'GET');
    $request->cookies->set(PublicCountryPreference::COOKIE_NAME, 'singapore');
    $request->cookies->set('user_timezone', 'Asia/Kuala_Lumpur');

    $resolved = app(PreferredCountryResolver::class)->resolveId($request);

    expect($resolved)->toBe($singaporeId);
});

it('falls back to Malaysia when the selected country is invalid', function () {
    $request = Request::create(route('events.index', [], false), 'GET');
    $request->cookies->set(PublicCountryPreference::COOKIE_NAME, 'unsupported-country');

    $resolved = app(PreferredCountryResolver::class)->resolveId($request);

    expect($resolved)->toBe(PreferredCountryResolver::MALAYSIA_ID);
});

it('falls back to Malaysia when timezone and CF-IPCountry are unavailable', function () {
    $request = Request::create(route('events.index', [], false), 'GET');

    $resolved = app(PreferredCountryResolver::class)->resolveId($request);

    expect($resolved)->toBe(PreferredCountryResolver::MALAYSIA_ID);
});
