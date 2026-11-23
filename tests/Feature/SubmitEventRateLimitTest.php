<?php

use App\Models\User;
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

test('the api enforces separate hourly submission limits for guests and token users on one ip', function () {
    $url = route('api.client.submit-event.store');

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson($url)->assertUnprocessable();
    }

    $this->postJson($url)->assertTooManyRequests();

    $user = User::factory()->create();
    $this->withToken($user->createToken('event-submission')->plainTextToken);

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $this->postJson($url)->assertUnprocessable();
    }

    $this->postJson($url)
        ->assertTooManyRequests()
        ->assertHeader('X-RateLimit-Limit', '20');

    $this->travel(1)->hour();

    $this->postJson($url)->assertUnprocessable();
});
