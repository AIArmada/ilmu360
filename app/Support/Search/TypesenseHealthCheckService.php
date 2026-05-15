<?php

declare(strict_types=1);

namespace App\Support\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Typesense\Client as TypesenseClient;

class TypesenseHealthCheckService
{
    /**
     * The cache duration for health check results (in seconds).
     */
    private const int HEALTH_CHECK_CACHE_TTL = 30;

    /**
     * The cache key for Typesense health status.
     */
    private const string HEALTH_CHECK_CACHE_KEY = 'typesense_health_check';

    /**
     * Check if Typesense is available without waiting for query timeout.
     *
     * Uses lightweight health endpoint check with caching to avoid repeated
     * requests to Typesense. Returns cached result if available.
     */
    public function isAvailable(): bool
    {
        // Skip check if not using Typesense
        if (config('scout.driver') !== 'typesense') {
            return false;
        }

        // Return cached result if available
        $cached = Cache::get(self::HEALTH_CHECK_CACHE_KEY);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $isHealthy = $this->checkTypesenseHealth();

        // Cache the result
        Cache::put(self::HEALTH_CHECK_CACHE_KEY, $isHealthy, self::HEALTH_CHECK_CACHE_TTL);

        return $isHealthy;
    }

    /**
     * Perform the actual health check against Typesense.
     *
     * Attempts to reach Typesense via a lightweight health endpoint.
     * Catches any connection or timeout errors and returns false.
     */
    private function checkTypesenseHealth(): bool
    {
        try {
            $settings = config('scout.typesense.client-settings');

            if (! is_array($settings)) {
                return false;
            }

            $client = new TypesenseClient($settings);

            // Attempt to fetch health - lightweight operation
            $client->health->retrieve();

            return true;
        } catch (Throwable $e) {
            Log::debug('Typesense health check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear the cached health status to force a re-check on next request.
     *
     * Useful for testing or manual health verification.
     */
    public function clearCache(): void
    {
        Cache::forget(self::HEALTH_CHECK_CACHE_KEY);
    }
}
