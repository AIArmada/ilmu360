<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\EventSubmissionOptionsCache;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class EventSubmissionOptionsCacheStore implements EventSubmissionOptionsCache
{
    private const int OPTIONS_TTL_SECONDS = 60;

    private const int CLIENT_CONFIGURATION_TTL_SECONDS = 300;

    private const string CLIENT_PROGRESS_CONFIGURATION_KEY = 'submit_event_client_progress_configuration';

    private const string MULTI_TIMEZONE_COUNTRIES_KEY = 'submit_diverging_timezone_countries_safe_v2';

    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberVenueOptions(?string $countryId, Closure $resolver): array
    {
        return Cache::remember(
            sprintf('submit_venues_%s_safe_v2', $countryId ?? 'all'),
            self::OPTIONS_TTL_SECONDS,
            $resolver,
        );
    }

    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberTaxonomyTagOptions(string $cachePrefix, string $locale, Closure $resolver): array
    {
        return Cache::remember(
            sprintf('%s_%s_safe_v2', $cachePrefix, $locale),
            self::OPTIONS_TTL_SECONDS,
            $resolver,
        );
    }

    /**
     * @param  Closure(): array{speaker_required_category_ids: list<string>}  $resolver
     * @return array{speaker_required_category_ids: list<string>}
     */
    public function rememberClientProgressConfiguration(Closure $resolver): array
    {
        return Cache::remember(
            self::CLIENT_PROGRESS_CONFIGURATION_KEY,
            self::CLIENT_CONFIGURATION_TTL_SECONDS,
            $resolver,
        );
    }

    /**
     * @param  Closure(): list<string>  $resolver
     * @return list<string>
     */
    public function rememberMultiTimezoneCountryIds(Closure $resolver): array
    {
        return Cache::remember(
            self::MULTI_TIMEZONE_COUNTRIES_KEY,
            self::CLIENT_CONFIGURATION_TTL_SECONDS,
            $resolver,
        );
    }
}
