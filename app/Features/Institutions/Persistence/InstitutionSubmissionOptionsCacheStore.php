<?php

declare(strict_types=1);

namespace App\Features\Institutions\Persistence;

use AIArmada\CommerceSupport\Support\CacheVersionStore;
use App\Features\Institutions\Api\InstitutionSubmissionOptionsCache;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class InstitutionSubmissionOptionsCacheStore implements InstitutionSubmissionOptionsCache
{
    private const string VERSION_KEY = 'submission_options:institutions:version:v1';

    private const int OPTIONS_TTL_SECONDS = 60;

    public function __construct(private CacheVersionStore $cacheVersions) {}

    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberGuestOptions(?string $countryId, Closure $resolver): array
    {
        return Cache::remember(
            'submit_institutions:'.($countryId ?? 'all').':'.$this->cacheVersions->version(self::VERSION_KEY),
            self::OPTIONS_TTL_SECONDS,
            $resolver,
        );
    }

    public function invalidate(): void
    {
        $this->cacheVersions->bump(self::VERSION_KEY);
    }
}
