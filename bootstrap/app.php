<?php

declare(strict_types=1);

use App\Http\Middleware\NormalizeApiJsonResponse;
use App\Http\Middleware\SetFilamentTimezone;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackDawahShareAttribution;
use App\Support\Api\ApiJsonResponseNormalizer;
use App\Support\Api\ApiResponseFactory;
use App\Support\Location\PublicCountryPreference;
use App\Support\Location\PublicGeolocationPermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            'user_timezone',
            PublicCountryPreference::COOKIE_NAME,
            PublicGeolocationPermission::COOKIE_NAME,
        ]);

        $middleware->api(append: [
            NormalizeApiJsonResponse::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            TrackDawahShareAttribution::class,
        ]);

        // Set default Filament timezone for every request (fixes Octane state persistence)
        $middleware->append(SetFilamentTimezone::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(static fn (Request $request): bool => ApiResponseFactory::isApiRequest($request) || $request->expectsJson());

        $exceptions->respond(static fn ($response, Throwable $exception, Request $request) => app(ApiJsonResponseNormalizer::class)->normalize($request, $response));
    })->create();
