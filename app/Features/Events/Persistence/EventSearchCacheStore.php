<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\DefaultEventSearchCacheResult;
use App\Features\Events\Api\EventSearchCache;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class EventSearchCacheStore implements EventSearchCache
{
    private const string DEFAULT_SEARCH_KEY = 'default_events_search_v2';

    private const int DEFAULT_SEARCH_TTL_SECONDS = 60;

    /**
     * @param  Closure(): array{ids: list<string>, total: int}  $resolver
     */
    public function rememberDefaultSearch(Closure $resolver): DefaultEventSearchCacheResult
    {
        /** @var array{ids: list<string>, total: int} $payload */
        $payload = Cache::remember(
            self::DEFAULT_SEARCH_KEY,
            self::DEFAULT_SEARCH_TTL_SECONDS,
            $resolver,
        );

        return new DefaultEventSearchCacheResult(
            ids: $payload['ids'],
            total: $payload['total'],
        );
    }

    public function forgetDefaultSearch(): void
    {
        Cache::forget(self::DEFAULT_SEARCH_KEY);
    }
}
