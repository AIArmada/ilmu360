<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Closure;

interface EventSearchCache
{
    /**
     * @param  Closure(): array{ids: list<string>, total: int}  $resolver
     */
    public function rememberDefaultSearch(string $scopeKey, Closure $resolver): DefaultEventSearchCacheResult;

    /**
     * @param  Closure(): array{rows: list<array{event_id: string, occurrence_id: string}>, total: int}  $resolver
     * @return array{rows: list<array{event_id: string, occurrence_id: string}>, total: int}
     */
    public function rememberDefaultSchedulePage(string $scopeKey, Closure $resolver): array;

    /**
     * @param  Closure(): list<array{event_id: string, occurrence_id: string}>  $resolver
     * @return list<array{event_id: string, occurrence_id: string}>
     */
    public function rememberHomepageFeatured(Closure $resolver): array;

    public function bust(): void;
}
