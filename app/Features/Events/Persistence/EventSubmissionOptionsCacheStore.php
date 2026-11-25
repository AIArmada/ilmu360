<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\EventSubmissionOptionsCache;
use App\Shared\Cache\DiscoveryCache;
use Closure;

final readonly class EventSubmissionOptionsCacheStore implements EventSubmissionOptionsCache
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberVenueOptions(?string $countryId, Closure $resolver): array
    {
        return $this->cache->remember(
            $this->versionKey(),
            'submit_venues:'.($countryId ?? 'all'),
            $this->ttlSeconds(),
            $resolver,
        );
    }

    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberTaxonomyTagOptions(string $cachePrefix, string $locale, Closure $resolver): array
    {
        return $this->cache->remember(
            $this->versionKey(),
            "{$cachePrefix}:{$locale}",
            $this->ttlSeconds(),
            $resolver,
        );
    }

    /**
     * @param  Closure(): array{speaker_required_category_ids: list<string>}  $resolver
     * @return array{speaker_required_category_ids: list<string>}
     */
    public function rememberClientProgressConfiguration(Closure $resolver): array
    {
        return $this->cache->remember(
            $this->versionKey(),
            'submit_event_client_progress_configuration',
            $this->ttlSeconds(),
            $resolver,
        );
    }

    /**
     * @param  Closure(): list<string>  $resolver
     * @return list<string>
     */
    public function rememberMultiTimezoneCountryIds(Closure $resolver): array
    {
        return $this->cache->remember(
            $this->versionKey(),
            'submit_diverging_timezone_countries',
            $this->ttlSeconds(),
            $resolver,
        );
    }

    public function invalidate(): void
    {
        $this->cache->bump($this->versionKey());
    }

    private function versionKey(): string
    {
        $configured = config('discovery.cache.versions.submission_events', 'discovery:v:submission:events');

        return is_string($configured) && $configured !== '' ? $configured : 'discovery:v:submission:events';
    }

    private function ttlSeconds(): int
    {
        return (int) config('discovery.cache.ttls.submission_options', 900);
    }
}
