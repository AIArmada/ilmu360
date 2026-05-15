<?php

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;

it('keeps critical app routes controller-backed so route caching stays safe', function () {
    $guardedRoutes = [
        'GET api/v1/user',
        'GET docs',
        'GET docs.json',
    ];

    $closureRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn (IlluminateRoute $route): array => [
            'route' => $route,
            'identifiers' => collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): string => $method.' '.$route->uri())
                ->values()
                ->all(),
        ])
        ->filter(fn (array $entry): bool => collect($entry['identifiers'])
            ->contains(fn (string $identifier): bool => in_array($identifier, $guardedRoutes, true)))
        ->filter(fn (array $entry): bool => $entry['route']->getActionName() === 'Closure')
        ->flatMap(fn (array $entry): array => $entry['identifiers'])
        ->filter(fn (string $identifier): bool => in_array($identifier, $guardedRoutes, true))
        ->values()
        ->all();

    expect($closureRoutes)->toBe([]);
});
