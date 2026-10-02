<?php

use Database\Seeders\AIArmada\EventRoleSeeder;

beforeEach(function () {
    $this->seed(EventRoleSeeder::class);
});

test('submit event route is not protected by event submission throttle middleware', function () {
    $route = app('router')->getRoutes()->getByName('submit-event.create');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->not->toContain('throttle:event-submission');
});

test('api submit event route is protected by event submission throttle middleware', function () {
    $route = app('router')->getRoutes()->getByName('api.client.submit-event.store');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:event-submission');
});
