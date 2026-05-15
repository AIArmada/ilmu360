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

it('treats unsupported and disabled saved country values as absent selections', function () {
    $request = Request::create(route('events.index', [], false), 'GET');
    $request->cookies->set(PublicCountryPreference::COOKIE_NAME, 'indonesia');

    $preference = app(PublicCountryPreference::class);

    expect($preference->selectedKey($request))->toBeNull()
        ->and($preference->currentKey($request))->toBe('malaysia');
});

it('falls back to a valid cookie selection when the session country value is stale', function () {
    $request = Request::create(route('events.index', [], false), 'GET');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put(PublicCountryPreference::SESSION_KEY, 'indonesia');
    $request->cookies->set(PublicCountryPreference::COOKIE_NAME, 'malaysia');

    $selectedKey = app(PublicCountryPreference::class)->selectedKey($request);

    expect($selectedKey)->toBe('malaysia');
});

it('ignores an invalid saved country so CF-IPCountry can still win later in resolution order', function () {
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
    $request->cookies->set(PublicCountryPreference::COOKIE_NAME, 'unsupported-country');
    $request->headers->set('CF-IPCountry', 'SG');

    $resolved = app(PreferredCountryResolver::class)->resolveId($request);

    expect($resolved)->toBe($singaporeId);
});
