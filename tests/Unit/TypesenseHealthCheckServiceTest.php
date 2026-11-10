<?php

use App\Platform\Search\TypesenseHealthCheckService;
use Tests\TestCase;

uses(TestCase::class);

it('returns false when scout driver is not typesense', function () {
    config()->set('scout.driver', 'database');

    $service = app(TypesenseHealthCheckService::class);
    $result = $service->isAvailable();

    expect($result)->toBeFalse();
});
