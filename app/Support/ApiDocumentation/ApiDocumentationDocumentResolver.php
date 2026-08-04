<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use Dedoc\Scramble\Generator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class ApiDocumentationDocumentResolver
{
    public function __construct(
        private readonly Generator $generator,
        private readonly ReconnectCachedDatabaseConnections $reconnectCachedDatabaseConnections,
        private readonly ApiDocumentationConfigFactory $configFactory,
        private readonly ApiDocumentationUrlResolver $urlResolver,
        private readonly ApiDocumentationVersionResolver $versionResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $cacheScope = sha1(implode('|', [
            app()->environment(),
            $this->urlResolver->apiBaseUrl(),
            (string) config('scramble.api_domain', ''),
            (string) config('scramble.api_path', 'api/v1'),
        ]));

        $cacheKey = 'api-documentation:openapi-json:'.$cacheScope.':'.$this->versionResolver->current();
        $latestCacheKeyPointer = 'api-documentation:openapi-json:latest-key:'.$cacheScope;
        $previousCacheKey = Cache::get($latestCacheKeyPointer);
        $cachedDocument = Cache::get($cacheKey);

        /** @var array<string, mixed> $document */
        $document = is_array($cachedDocument)
            ? $cachedDocument
            : $this->resolveUncachedDocument($cacheKey, $previousCacheKey);

        // A stale response must not promote the pointer to an uncached document.
        if ($previousCacheKey !== $cacheKey && Cache::has($cacheKey)) {
            if (is_string($previousCacheKey) && $previousCacheKey !== '') {
                Cache::forget($previousCacheKey);
            }

            Cache::forever($latestCacheKeyPointer, $cacheKey);
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveUncachedDocument(string $cacheKey, mixed $previousCacheKey): array
    {
        $hasPreviousDocument = is_string($previousCacheKey)
            && $previousCacheKey !== ''
            && is_array(Cache::get($previousCacheKey));

        try {
            // A stale document is already a valid response. Do not make callers
            // wait while a large OpenAPI document is being regenerated elsewhere.
            $lockWaitSeconds = $hasPreviousDocument ? 0 : 10;

            $document = Cache::lock($cacheKey.':lock', 120)->block($lockWaitSeconds, function () use ($cacheKey): array {
                $lockedCachedDocument = Cache::get($cacheKey);

                if (is_array($lockedCachedDocument)) {
                    return $lockedCachedDocument;
                }

                return $this->generateDocument($cacheKey);
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

        return $this->generateDocument($cacheKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function generateDocument(string $cacheKey): array
    {
        // Scramble's full route graph can exceed the production request budget in
        // a cold test process. CLI test workers have no request timeout, so keep
        // that environment uncapped while retaining the production safeguard.
        if (function_exists('set_time_limit') && ! app()->runningInConsole()) {
            @set_time_limit(120);
        }

        ($this->reconnectCachedDatabaseConnections)();

        /** @var array<string, mixed> $document */
        $document = ($this->generator)($this->configFactory->make());
        Cache::forever($cacheKey, $document);

        return $document;
    }
}
