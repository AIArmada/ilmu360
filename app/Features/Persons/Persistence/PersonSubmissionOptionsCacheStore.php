<?php

declare(strict_types=1);

namespace App\Features\Persons\Persistence;

use App\Features\Persons\Api\PersonSubmissionOptionsCache;
use App\Shared\Cache\DiscoveryCache;
use Closure;

/**
 * Guest person submission-options cache store.
 */
final readonly class PersonSubmissionOptionsCacheStore implements PersonSubmissionOptionsCache
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberGuestPersonOptions(Closure $resolver): array
    {
        return $this->cache->remember(
            $this->versionKey(),
            'submission_options:persons:guest',
            (int) config('discovery.cache.ttls.submission_options', 900),
            $resolver,
        );
    }

    public function forgetGuestPersonOptions(): void
    {
        $this->cache->bump($this->versionKey());
    }

    private function versionKey(): string
    {
        $configured = config('discovery.cache.versions.submission_persons', 'discovery:v:submission:persons');

        return is_string($configured) && $configured !== '' ? $configured : 'discovery:v:submission:persons';
    }
}
