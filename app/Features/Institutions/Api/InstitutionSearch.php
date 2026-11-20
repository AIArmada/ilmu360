<?php

declare(strict_types=1);

namespace App\Features\Institutions\Api;

use App\Shared\Discovery\PublicDiscoveryAdapter;

/**
 * Public institution search boundary consumed by aggregate discovery.
 */
interface InstitutionSearch extends PublicDiscoveryAdapter
{
    /**
     * Resolve public institution ids for a search, falling back to fuzzy
     * matching exactly like the owner's directory search.
     *
     * @return list<string>
     */
    public function resolvedPublicSearchIds(string $search): array;
}
