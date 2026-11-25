<?php

declare(strict_types=1);

namespace App\Platform\Search;

use App\Shared\Cache\DiscoveryCache;
use App\Shared\Contracts\SearchHealthCheck;
use App\Shared\Contracts\SearchHealthProbe;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class CachedSearchHealthCheck implements SearchHealthCheck
{
    private const string VERSION_KEY = 'discovery:v:search:health';

    private const string CACHE_KEY = 'search_health_check';

    public function __construct(
        private SearchHealthProbe $healthProbe,
        private DiscoveryCache $cache,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->activeDriver() !== 'typesense') {
            return false;
        }

        $ttl = max(1, (int) config('discovery.cache.ttls.health_check', 30));

        $isHealthy = $this->cache->remember(
            self::VERSION_KEY,
            self::CACHE_KEY,
            $ttl,
            fn (): bool => $this->probeHealth(),
        );

        return (bool) $isHealthy;
    }

    public function bust(): void
    {
        $this->cache->bump(self::VERSION_KEY);
    }

    private function probeHealth(): bool
    {
        $settings = config('scout.typesense.client-settings');

        if (! is_array($settings)) {
            return false;
        }

        try {
            return $this->healthProbe->isHealthy($settings);
        } catch (Throwable $exception) {
            Log::debug('Search health check failed', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function activeDriver(): string
    {
        $configured = config('discovery.search.driver');

        if (is_string($configured) && trim($configured) !== '') {
            return strtolower(trim($configured));
        }

        return strtolower(trim((string) config('scout.driver')));
    }
}
