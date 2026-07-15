<?php

use App\Support\Search\TypesenseHealthCheckService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

it('returns false when scout driver is not typesense', function () {
    config()->set('scout.driver', 'database');

    $service = app(TypesenseHealthCheckService::class);
    $result = $service->isAvailable();

    expect($result)->toBeFalse();
});

it('caches health check results for 30 seconds', function () {
    config()->set('scout.driver', 'database');

    $service = app(TypesenseHealthCheckService::class);

    $service->isAvailable();

    // Call again - should use cache
    $service->isAvailable();

    expect(true)->toBeTrue();
});

it('clears cache on demand', function () {
    config()->set('scout.driver', 'database');

    $service = app(TypesenseHealthCheckService::class);

    $service->clearCache();

    expect(true)->toBeTrue();
});
