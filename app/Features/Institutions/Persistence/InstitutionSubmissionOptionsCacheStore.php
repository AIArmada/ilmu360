<?php

declare(strict_types=1);

namespace App\Features\Institutions\Persistence;

use App\Features\Institutions\Api\InstitutionSubmissionOptionsCache;
use App\Shared\Cache\DiscoveryCache;
use Closure;

final readonly class InstitutionSubmissionOptionsCacheStore implements InstitutionSubmissionOptionsCache
{
    public function __construct(
        private DiscoveryCache $cache,
    ) {}

    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberGuestOptions(?string $countryId, Closure $resolver): array
    {
        return $this->cache->remember(
            $this->versionKey(),
            'submission_options:institutions:guest:'.($countryId ?? 'all'),
            (int) config('discovery.cache.ttls.submission_options', 900),
            $resolver,
        );
    }

    public function invalidate(): void
    {
        $this->cache->bump($this->versionKey());
    }

    private function versionKey(): string
    {
        $configured = config('discovery.cache.versions.submission_institutions', 'discovery:v:submission:institutions');

        return is_string($configured) && $configured !== '' ? $configured : 'discovery:v:submission:institutions';
    }
}
