<?php

use App\Platform\Search\TypesenseHealthCheckService;
use App\Shared\Contracts\SearchHealthProbe;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('returns false when scout driver is not typesense', function () {
    config()->set('scout.driver', 'database');

    $service = app(TypesenseHealthCheckService::class);
    $result = $service->isAvailable();

    expect($result)->toBeFalse();
});

it('consults the host probe once and caches the health result', function () {
    config()->set('scout.driver', 'typesense');
    config()->set('scout.typesense.client-settings', ['api_key' => 'test', 'nodes' => []]);
    Cache::forget('typesense_health_check');

    $probe = Mockery::mock(SearchHealthProbe::class);
    $probe->shouldReceive('isHealthy')->once()->with(['api_key' => 'test', 'nodes' => []])->andReturn(true);
    app()->instance(SearchHealthProbe::class, $probe);

    $service = app(TypesenseHealthCheckService::class);
    expect($service->isAvailable())->toBeTrue()
        ->and($service->isAvailable())->toBeTrue();
});

it('does not call the host probe for incomplete configuration', function () {
    config()->set('scout.driver', 'typesense');
    config()->set('scout.typesense.client-settings');
    Cache::forget('typesense_health_check');

    $probe = Mockery::mock(SearchHealthProbe::class);
    $probe->shouldNotReceive('isHealthy');
    app()->instance(SearchHealthProbe::class, $probe);

    expect(app(TypesenseHealthCheckService::class)->isAvailable())->toBeFalse();
});
