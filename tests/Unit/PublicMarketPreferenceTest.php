<?php

use App\Support\Location\PreferredCountryResolver;
use App\Support\Location\PublicCountryPreference;
use App\Support\Location\PublicCountryRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const LEGACY_PUBLIC_MARKET_COOKIE = 'public_market';

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

it('ignores the legacy public_market cookie when resolving the selected country', function () {
    $request = Request::create(route('events.index', [], false), 'GET');
    $request->cookies->set(LEGACY_PUBLIC_MARKET_COOKIE, 'indonesia');

    $preference = app(PublicCountryPreference::class);

    expect($preference->selectedKey($request))->toBeNull()
        ->and($preference->currentKey($request))->toBe('malaysia');
});

it('ignores the legacy public_market session key when a current country cookie is available', function () {
    $request = Request::create(route('events.index', [], false), 'GET');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put('public_market', 'indonesia');
    $request->cookies->set(PublicCountryPreference::COOKIE_NAME, 'malaysia');

    $selectedKey = app(PublicCountryPreference::class)->selectedKey($request);

    expect($selectedKey)->toBe('malaysia');
});

it('ignores the legacy public_market cookie so country inference can still use timezone and ip resolution', function () {
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
    $request->cookies->set(LEGACY_PUBLIC_MARKET_COOKIE, 'malaysia');
    $request->cookies->set('user_timezone', 'Asia/Singapore');
    $request->headers->set('CF-IPCountry', 'SG');

    $resolved = app(PreferredCountryResolver::class)->resolveId($request);

    expect($resolved)->toBe($singaporeId);
});
