<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Exceptions\NoCurrentOwnerException;
use AIArmada\CommerceSupport\Middleware\SetExplicitGlobalOwnerContext;
use AIArmada\Organizations\Http\Middleware\CurrentOrganizationMiddleware;
use App\Features\Contributions\Exceptions\ContributionRequestNotPendingException;
use App\Features\ShareTracking\EntryPoints\Http\Middleware\TrackShareAttribution;
use App\Platform\Api\ApiJsonResponseNormalizer;
use App\Platform\Api\ApiResponseFactory;
use App\Platform\Api\Http\Middleware\NormalizeApiJsonResponse;
use App\Platform\Language\Http\Middleware\SetLocale;
use App\Platform\Location\PublicGeolocationPermission;
use App\Platform\Time\Http\Middleware\SetFilamentTimezone;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
        App\Platform\Media\Commands\MigrateMediaToNewStructure::class,
        App\Platform\Mcp\Commands\IssueMcpToken::class,
        App\Features\ShareTracking\EntryPoints\Console\ActivateLocalShareExamples::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'current.organization' => CurrentOrganizationMiddleware::class,
        ]);

        // Cloudflare Tunnel terminates public TLS at the edge and forwards to
        // Herd over loopback. Trust loopback proxies only so forwarded
        // headers (proto/host/ip) are honored without trusting the open internet.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->encryptCookies(except: [
            'user_timezone',
            PublicGeolocationPermission::COOKIE_NAME,
        ]);

        $middleware->api(append: [
            NormalizeApiJsonResponse::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            TrackShareAttribution::class,
            SetFilamentTimezone::class,
        ]);

        $middleware->append(SetExplicitGlobalOwnerContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(static fn (Request $request): bool => ApiResponseFactory::isApiRequest($request) || $request->expectsJson());

        $exceptions->render(static fn (NoCurrentOwnerException $exception): JsonResponse => response()->json([
            'message' => $exception->getMessage(),
            'error' => [
                'code' => 'forbidden',
                'message' => $exception->getMessage(),
            ],
        ], 403));

        $exceptions->render(static function (LockTimeoutException $exception, Request $request): ?JsonResponse {
            if (! ApiResponseFactory::isApiRequest($request) && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'error' => [
                    'code' => 'service_unavailable',
                    'message' => $exception->getMessage(),
                ],
            ], 503, ['Retry-After' => (int) config('moderation.review_lock_block_seconds', 10)]);
        });

        $exceptions->render(static function (ContributionRequestNotPendingException $exception, Request $request): ?JsonResponse {
            if (! ApiResponseFactory::isApiRequest($request) && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'error' => [
                    'code' => 'conflict',
                    'message' => $exception->getMessage(),
                ],
            ], 409);
        });

        $exceptions->respond(static fn ($response, Throwable $exception, Request $request) => app(ApiJsonResponseNormalizer::class)->normalize($request, $response));
    })->create();

if (($_SERVER['ILMU360_TEST_SUITE'] ?? null) === '1') {
    $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
        if (! $app->environment('testing') || $app['config_loaded_from_cache']) {
            throw new RuntimeException('The test suite requires uncached configuration in the testing environment.');
        }

        $config = $app['config'];
        $useCiPostgres = getenv('GITHUB_ACTIONS') === 'true' && getenv('ILMU360_TEST_POSTGRES') === '1';
        $driver = $useCiPostgres ? 'pgsql' : 'sqlite';
        $connection = $config->get("database.connections.{$driver}");
        $connection['url'] = null;
        $connection['database'] = $useCiPostgres ? 'test' : ':memory:';

        if ($useCiPostgres) {
            $connection['host'] = '127.0.0.1';
            $connection['port'] = 5432;
        }

        $config->set('database.default', $driver);
        $config->set('database.connections', [$driver => $connection]);
    });
}

return $app;
