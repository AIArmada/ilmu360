<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Closure;

interface EventSubmissionOptionsCache
{
    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberVenueOptions(?string $countryId, Closure $resolver): array;

    /**
     * @param  Closure(): array<string, string>  $resolver
     * @return array<string, string>
     */
    public function rememberTaxonomyTagOptions(string $cachePrefix, string $locale, Closure $resolver): array;

    /**
     * @param  Closure(): array{speaker_required_category_ids: list<string>}  $resolver
     * @return array{speaker_required_category_ids: list<string>}
     */
    public function rememberClientProgressConfiguration(Closure $resolver): array;

    /**
     * @param  Closure(): list<string>  $resolver
     * @return list<string>
     */
    public function rememberMultiTimezoneCountryIds(Closure $resolver): array;
}
