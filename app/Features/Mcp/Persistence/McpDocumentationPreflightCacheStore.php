<?php

declare(strict_types=1);

namespace App\Features\Mcp\Persistence;

use App\Features\Mcp\Api\McpDocumentationAudience;
use App\Features\Mcp\Api\McpDocumentationPreflightState;
use Illuminate\Support\Facades\Cache;

final readonly class McpDocumentationPreflightCacheStore implements McpDocumentationPreflightState
{
    private const string CACHE_KEY_PREFIX = 'mcp:documentation-preflight:';

    private const int CACHE_TTL_HOURS = 12;

    /**
     * @param  list<string>  $contextKeys
     */
    public function markGuideSeen(McpDocumentationAudience $audience, array $contextKeys): void
    {
        foreach ($contextKeys as $contextKey) {
            Cache::put(
                $this->cacheKey($audience, $contextKey),
                true,
                now()->addHours(self::CACHE_TTL_HOURS),
            );
        }
    }

    /**
     * @param  list<string>  $contextKeys
     */
    public function hasGuideBeenSeen(McpDocumentationAudience $audience, array $contextKeys): bool
    {
        return array_any(
            $contextKeys,
            fn (string $contextKey): bool => Cache::get($this->cacheKey($audience, $contextKey), false) === true,
        );
    }

    private function cacheKey(McpDocumentationAudience $audience, string $contextKey): string
    {
        return self::CACHE_KEY_PREFIX.$audience->value.':'.$contextKey;
    }
}
