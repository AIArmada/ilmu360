<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\DefaultEventSearchCacheResult;
use App\Features\Events\Api\EventSearchCache;
use App\Shared\Cache\DiscoveryCache;
use Closure;

final readonly class EventSearchCacheStore implements EventSearchCache
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    /**
     * @param  Closure(): array{ids: list<string>, total: int}  $resolver
     */
    public function rememberDefaultSearch(string $scopeKey, Closure $resolver): DefaultEventSearchCacheResult
    {
        /** @var array{ids: list<string>, total: int} $payload */
        $payload = $this->cache->remember(
            $this->versionKey(),
            "default_events_search:{$scopeKey}",
            (int) config('discovery.cache.ttls.default_schedule', 60),
            $resolver,
        );

        return new DefaultEventSearchCacheResult(
            ids: $payload['ids'],
            total: $payload['total'],
        );
    }

    /**
     * @param  Closure(): array{rows: list<array{event_id: string, occurrence_id: string}>, total: int}  $resolver
     * @return array{rows: list<array{event_id: string, occurrence_id: string}>, total: int}
     */
    public function rememberDefaultSchedulePage(string $scopeKey, Closure $resolver): array
    {
        /** @var array{rows: list<array{event_id: string, occurrence_id: string}>, total: int} $payload */
        $payload = $this->cache->remember(
            $this->versionKey(),
            "default_schedule_page:{$scopeKey}",
            (int) config('discovery.cache.ttls.default_schedule', 60),
            $resolver,
        );

        return $payload;
    }

    /**
     * @param  Closure(): list<array{event_id: string, occurrence_id: string}>  $resolver
     * @return list<array{event_id: string, occurrence_id: string}>
     */
    public function rememberHomepageFeatured(Closure $resolver): array
    {
        /** @var list<array{event_id: string, occurrence_id: string}> $payload */
        $payload = $this->cache->remember(
            $this->versionKey(),
            'homepage_featured_occurrences',
            (int) config('discovery.cache.ttls.homepage_featured', 120),
            $resolver,
        );

        return $payload;
    }

    public function bust(): void
    {
        $this->cache->bump($this->versionKey());
    }

    private function versionKey(): string
    {
        $configured = config('discovery.cache.versions.events_schedule', 'discovery:v:events:schedule');

        return is_string($configured) && $configured !== '' ? $configured : 'discovery:v:events:schedule';
    }
}
