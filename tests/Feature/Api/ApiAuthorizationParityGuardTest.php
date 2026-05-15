<?php

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('does not use Sanctum ability middleware on registered api routes without an explicit exception', function () {
    $allowedRouteIdentifiers = allowedAbilityMiddlewareRouteIdentifiers();

    $violations = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/'))
        ->flatMap(function (IlluminateRoute $route) use ($allowedRouteIdentifiers): array {
            $identifier = $route->getName() ?? $route->uri();

            if (in_array($identifier, $allowedRouteIdentifiers, true) || in_array($route->uri(), $allowedRouteIdentifiers, true)) {
                return [];
            }

            $abilityMiddleware = collect($route->gatherMiddleware())
                ->filter(fn (mixed $middleware): bool => is_string($middleware)
                    && (str_starts_with($middleware, 'abilities:') || str_starts_with($middleware, 'ability:')))
                ->values()
                ->all();

            if ($abilityMiddleware === []) {
                return [];
            }

            return [sprintf(
                '%s [%s] => %s',
                $identifier,
                implode(', ', $route->methods()),
                implode(', ', $abilityMiddleware),
            )];
        })
        ->all();

    expect($violations)->toBe([]);
});

it('does not call tokenCan in runtime authorization code without an explicit exception', function () {
    $allowedPaths = allowedTokenCanPaths();

    $violations = collect(runtimePhpFilesForAuthorizationParityGuard())
        ->flatMap(function (SplFileInfo $file) use ($allowedPaths): array {
            $path = $file->getPathname();
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

            if (in_array($relativePath, $allowedPaths, true)) {
                return [];
            }

            $matches = [];

            foreach (token_get_all(File::get($path)) as $token) {
                if (! is_array($token)) {
                    continue;
                }

                if ($token[0] !== T_STRING || strtolower($token[1]) !== 'tokencan') {
                    continue;
                }

                $matches[] = sprintf('%s:%d', $relativePath, $token[2]);
            }

            return $matches;
        })
        ->all();

    expect($violations)->toBe([]);
});

/**
 * @return array<int, string>
 */
function allowedAbilityMiddlewareRouteIdentifiers(): array
{
    return [];
}

/**
 * @return array<int, string>
 */
function allowedTokenCanPaths(): array
{
    return [];
}

/**
 * @return array<int, SplFileInfo>
 */
function runtimePhpFilesForAuthorizationParityGuard(): array
{
    $bootstrapFiles = collect(File::files(base_path('bootstrap')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->all();

    return [
        ...File::allFiles(app_path()),
        ...File::allFiles(base_path('routes')),
        ...$bootstrapFiles,
    ];
}
