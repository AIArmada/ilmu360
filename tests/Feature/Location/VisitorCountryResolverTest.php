<?php

declare(strict_types=1);

use AIArmada\Addressing\Models\AddressCountry;
use App\Livewire\Pages\Events\Index;
use App\Support\Location\VisitorCountryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    app(VisitorCountryResolver::class)->forget();
});

it('falls back to the configured country when there is no edge header', function (): void {
    ensureTestMalaysiaCountry();

    $resolved = app(VisitorCountryResolver::class)->resolveWithSource(
        Request::create('/majlis')
    );

    expect($resolved['country_code'])->toBe('MY')
        ->and($resolved['source'])->toBe('app_fallback')
        ->and($resolved['country_id'])->toBe(testMalaysiaCountryId());
});

it('prefers the cloudflare country header over the configured fallback', function (): void {
    ensureTestMalaysiaCountry();
    ensureTestAddressCountry('SG', 'Singapore');

    $request = Request::create('/majlis');
    $request->headers->set('CF-IPCountry', 'SG');

    $resolved = app(VisitorCountryResolver::class)->resolveWithSource($request);

    expect($resolved['country_code'])->toBe('SG')
        ->and($resolved['source'])->toBe('edge_header')
        ->and($resolved['country_id'])->toBe((string) AddressCountry::query()->where('iso2', 'SG')->firstOrFail()->getKey());
});

it('normalises a lowercase cloudflare country header', function (): void {
    ensureTestMalaysiaCountry();
    ensureTestAddressCountry('ID', 'Indonesia');

    $request = Request::create('/majlis');
    $request->headers->set('CF-IPCountry', ' id ');

    expect(app(VisitorCountryResolver::class)->resolveCode($request))->toBe('ID');
});

it('ignores indeterminate cloudflare codes and falls back', function (string $code): void {
    ensureTestMalaysiaCountry();

    $request = Request::create('/majlis');
    $request->headers->set('CF-IPCountry', $code);

    $resolved = app(VisitorCountryResolver::class)->resolveWithSource($request);

    expect($resolved['country_code'])->toBe('MY')
        ->and($resolved['source'])->toBe('app_fallback');
})->with(['XX', 'T1']);

it('ignores a malformed or forged country header', function (string $code): void {
    ensureTestMalaysiaCountry();

    $request = Request::create('/majlis');
    $request->headers->set('CF-IPCountry', $code);

    $resolved = app(VisitorCountryResolver::class)->resolveWithSource($request);

    expect($resolved['country_code'])->toBe('MY')
        // A country that does not exist must not be allowed through.
        ->and($resolved['country_id'])->toBe(testMalaysiaCountryId());
})->with(['ZZZZ', '1', 'MYX', '<script>', '']);

it('falls back when the header country is not seeded', function (): void {
    ensureTestMalaysiaCountry();

    $request = Request::create('/majlis');
    $request->headers->set('CF-IPCountry', 'AQ'); // Antarctica, not in the countries table

    $resolved = app(VisitorCountryResolver::class)->resolveWithSource($request);

    expect($resolved['country_code'])->toBe('MY')
        ->and($resolved['source'])->toBe('app_fallback');
});

it('ignores the header entirely when geoip resolution is disabled', function (): void {
    ensureTestMalaysiaCountry();
    ensureTestAddressCountry('SG', 'Singapore');

    config()->set('location.geoip.enabled', false);

    $request = Request::create('/majlis');
    $request->headers->set('CF-IPCountry', 'SG');

    expect(app(VisitorCountryResolver::class)->resolveCode($request))->toBe('MY');
});

it('honours a configured alternate header name', function (): void {
    ensureTestMalaysiaCountry();
    ensureTestAddressCountry('SG', 'Singapore');

    config()->set('location.geoip.header', 'X-Country-Code');

    $request = Request::create('/majlis');
    $request->headers->set('X-Country-Code', 'SG');
    $request->headers->set('CF-IPCountry', 'MY');

    expect(app(VisitorCountryResolver::class)->resolveCode($request))->toBe('SG');
});

it('memoises the resolution for the current request', function (): void {
    ensureTestMalaysiaCountry();

    $resolver = app(VisitorCountryResolver::class);

    expect($resolver->resolveCode(Request::create('/majlis')))->toBe('MY');

    $request = Request::create('/majlis');
    $request->headers->set('CF-IPCountry', 'SG');
    ensureTestAddressCountry('SG', 'Singapore');

    // Same request scope, so the earlier resolution stands.
    expect($resolver->resolveCode($request))->toBe('MY');

    $resolver->forget();

    expect($resolver->resolveCode($request))->toBe('SG');
});

it('scopes the majlis directory to the visitor country from the edge header', function (): void {
    ensureTestMalaysiaCountry();
    $singapore = ensureTestAddressCountry('SG', 'Singapore');

    $this->withHeaders(['CF-IPCountry' => 'SG'])->get('/majlis')->assertOk();

    Livewire\Livewire::withHeaders(['CF-IPCountry' => 'SG'])
        ->test(Index::class)
        ->assertSet('country_id', (string) $singapore->getKey());
});

it('scopes the majlis directory to malaysia when there is no edge header', function (): void {
    ensureTestMalaysiaCountry();

    Livewire\Livewire::test(Index::class)
        ->assertSet('country_id', testMalaysiaCountryId());
});

it('keeps an explicitly chosen country instead of the visitor default', function (): void {
    ensureTestMalaysiaCountry();
    $singapore = ensureTestAddressCountry('SG', 'Singapore');

    Livewire\Livewire::withHeaders(['CF-IPCountry' => 'SG'])
        ->test(Index::class)
        ->assertSet('country_id', (string) $singapore->getKey());

    // An explicit URL value must win over the inferred default.
    $this->withHeaders(['CF-IPCountry' => 'SG'])
        ->get('/majlis?country_id='.testMalaysiaCountryId())
        ->assertOk();
});

it('does not count the automatic country scope as an active filter', function (): void {
    ensureTestMalaysiaCountry();

    // "Negara" is the country select's label and always renders; the active
    // filter chip renders as "Negara: <name>" and must not appear.
    Livewire\Livewire::test(Index::class)
        ->assertSet('country_id', testMalaysiaCountryId())
        ->assertDontSee(__('Negara').': Malaysia');
});

it('restores the visitor country when all filters are cleared', function (): void {
    ensureTestMalaysiaCountry();

    Livewire\Livewire::test(Index::class)
        ->set('filterData.state_id', null)
        ->call('clearAllFilters')
        ->assertSet('country_id', testMalaysiaCountryId());
});
