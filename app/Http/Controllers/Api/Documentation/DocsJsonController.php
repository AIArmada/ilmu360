<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use App\Http\Controllers\Controller;
use App\Support\ApiDocumentation\ApiDocumentationConfigFactory;
use App\Support\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Support\ApiDocumentation\ApiDocumentationVersionResolver;
use App\Support\ApiDocumentation\ReconnectCachedDatabaseConnections;
use Dedoc\Scramble\Generator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DocsJsonController extends Controller
{
    public function __invoke(
        Request $request,
        Generator $generator,
        ReconnectCachedDatabaseConnections $reconnectCachedDatabaseConnections,
        ApiDocumentationConfigFactory $configFactory,
        ApiDocumentationUrlResolver $urlResolver,
        ApiDocumentationVersionResolver $versionResolver,
    ): JsonResponse {
        $cacheScope = sha1(implode('|', [
            app()->environment(),
            $urlResolver->apiBaseUrl(),
            (string) config('scramble.api_domain', ''),
            (string) config('scramble.api_path', 'api/v1'),
        ]));

        $cacheKey = 'api-documentation:openapi-json:'.$cacheScope.':'.$versionResolver->current();
        $latestCacheKeyPointer = 'api-documentation:openapi-json:latest-key:'.$cacheScope;
        $previousCacheKey = Cache::get($latestCacheKeyPointer);

        $cachedDocument = Cache::get($cacheKey);

        /** @var array<string, mixed> $document */
        $document = is_array($cachedDocument)
            ? $cachedDocument
            : $this->resolveDocument(
                $cacheKey,
                $previousCacheKey,
                $reconnectCachedDatabaseConnections,
                $configFactory,
                $generator,
            );

        if ($previousCacheKey !== $cacheKey) {
            if (is_string($previousCacheKey) && $previousCacheKey !== '') {
                Cache::forget($previousCacheKey);
            }

            Cache::forever($latestCacheKeyPointer, $cacheKey);
        }

        $response = response()->json($document, options: JSON_PRETTY_PRINT);

        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(3600);
        $response->headers->addCacheControlDirective('stale-while-revalidate', '86400');
        $response->headers->set('Vary', 'Accept, Host');
        $response->setEtag(sha1($cacheKey));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDocument(
        string $cacheKey,
        ?string $previousCacheKey,
        ReconnectCachedDatabaseConnections $reconnectCachedDatabaseConnections,
        ApiDocumentationConfigFactory $configFactory,
        Generator $generator,
    ): array {
        try {
            $document = Cache::lock($cacheKey.':lock', 120)->block(10, function () use ($cacheKey, $reconnectCachedDatabaseConnections, $configFactory, $generator): array {
                $lockedCachedDocument = Cache::get($cacheKey);

                if (is_array($lockedCachedDocument)) {
                    return $lockedCachedDocument;
                }

                return $this->generateDocument($cacheKey, $reconnectCachedDatabaseConnections, $configFactory, $generator);
            });

            if (is_array($document)) {
                return $document;
            }
        } catch (LockTimeoutException) {
        }

        $cachedDocument = Cache::get($cacheKey);

        if (is_array($cachedDocument)) {
            return $cachedDocument;
        }

        if (is_string($previousCacheKey) && $previousCacheKey !== '') {
            $previousDocument = Cache::get($previousCacheKey);

            if (is_array($previousDocument)) {
                return $previousDocument;
            }
        }

        return $this->generateDocument($cacheKey, $reconnectCachedDatabaseConnections, $configFactory, $generator);
    }

    /**
     * @return array<string, mixed>
     */
    private function generateDocument(
        string $cacheKey,
        ReconnectCachedDatabaseConnections $reconnectCachedDatabaseConnections,
        ApiDocumentationConfigFactory $configFactory,
        Generator $generator,
    ): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $reconnectCachedDatabaseConnections();

        /** @var array<string, mixed> $generatedDocument */
        $generatedDocument = $generator($configFactory->make());
        Cache::forever($cacheKey, $generatedDocument);

        return $generatedDocument;
    }
}
