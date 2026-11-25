<?php

declare(strict_types=1);

use App\Shared\Contracts\SearchHealthCheck;
use App\Shared\Contracts\SearchHealthProbe;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    config()->set('discovery.cache.enabled', true);
    config()->set('discovery.cache.store', null);
    config()->set('cache.default', 'array');
    app('cache')->setDefaultDriver('array');
    Cache::flush();
});

it('returns false when active search driver is not typesense', function (): void {
    config()->set('discovery.search.driver', null);
    config()->set('scout.driver', 'database');

    $probe = Mockery::mock(SearchHealthProbe::class);
    $probe->shouldNotReceive('isHealthy');
    app()->instance(SearchHealthProbe::class, $probe);

    expect(app(SearchHealthCheck::class)->isAvailable())->toBeFalse();
});

it('consults the host probe once and caches the health result via DiscoveryCache', function (): void {
    config()->set('discovery.search.driver', null);
    config()->set('scout.driver', 'typesense');
    config()->set('scout.typesense.client-settings', ['api_key' => 'test', 'nodes' => []]);
    config()->set('discovery.cache.ttls.health_check', 30);

    $probe = Mockery::mock(SearchHealthProbe::class);
    $probe->shouldReceive('isHealthy')
        ->once()
        ->with(['api_key' => 'test', 'nodes' => []])
        ->andReturn(true);
    app()->instance(SearchHealthProbe::class, $probe);

    $healthCheck = app(SearchHealthCheck::class);

    expect($healthCheck->isAvailable())->toBeTrue()
        ->and($healthCheck->isAvailable())->toBeTrue();
});

it('does not call the host probe for incomplete configuration', function (): void {
    config()->set('discovery.search.driver', null);
    config()->set('scout.driver', 'typesense');
    config()->set('scout.typesense.client-settings');

    $probe = Mockery::mock(SearchHealthProbe::class);
    $probe->shouldNotReceive('isHealthy');
    app()->instance(SearchHealthProbe::class, $probe);

    expect(app(SearchHealthCheck::class)->isAvailable())->toBeFalse();
});

it('re-probes health after busting the cached health version', function (): void {
    config()->set('discovery.search.driver', null);
    config()->set('scout.driver', 'typesense');
    config()->set('scout.typesense.client-settings', ['api_key' => 'test', 'nodes' => []]);

    $probe = Mockery::mock(SearchHealthProbe::class);
    $probe->shouldReceive('isHealthy')
        ->twice()
        ->with(['api_key' => 'test', 'nodes' => []])
        ->andReturn(true, false);
    app()->instance(SearchHealthProbe::class, $probe);

    $healthCheck = app(SearchHealthCheck::class);

    expect($healthCheck->isAvailable())->toBeTrue()
        ->and($healthCheck->isAvailable())->toBeTrue();

    $healthCheck->bust();

    expect($healthCheck->isAvailable())->toBeFalse();
});
