<?php

test('submit event route is not protected by event submission throttle middleware', function () {
    $route = app('router')->getRoutes()->getByName('submit-event.create');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->not->toContain('throttle:event-submission');
});
